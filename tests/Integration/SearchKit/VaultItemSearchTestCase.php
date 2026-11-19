<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\SearchKit;

use DateTimeImmutable;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Test\QueryCountingMiddleware;
use Nowo\VaultBundle\Bridge\SearchKit\VaultItemSearchDefinition;
use Nowo\VaultBundle\Bridge\SearchKit\VaultSearchViewers;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultGrantRepository;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function class_exists;
use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

// SearchKit (>= 1.9) needs PHP >= 8.3 and stays optional: without it (the PHP 8.2 CI cell) this file declares nothing.
if (!class_exists(EntitySearchProviderTestCase::class)) {
    return;
}

/**
 * The SearchKit contract kit against the real VaultItemSearchDefinition, Vault's own guard and grant repository and a
 * real database (SQLite by default; MySQL / MariaDB / PostgreSQL through VAULT_TEST_DATABASE_URL in the CI job
 * "SearchKit on databases"). The SearchKit "tenant" is the viewer's vault user id; a hidden row is an item of a scope
 * vault the viewer is not a member of (the SQL keeps scope items, the scope access resolver refuses them).
 */
abstract class VaultItemSearchTestCase extends EntitySearchProviderTestCase
{
    /** The scope nobody of the kit is a member of (seedHidden()). */
    protected const HIDDEN_SCOPE = 'skc-hidden';

    private static ?EntityManagerInterface $em = null;

    private static ?QueryCounter $counter = null;

    private static int $clockTick = 0;

    protected EventDispatcher $vaultEvents;

    protected TokenStorage $tokens;

    protected RequestScopedScopeAccessCache $scopeCache;

    /** @var array<string, array<string, VaultScopeAccess>> user id => scope => access */
    protected array $members = [];

    /** @var array<string, list<string>> user id => team ids */
    protected array $teamIds = [];

    protected bool $listAllowed = true;

    protected bool $scopesEnabled = true;

    /**
     * The user mapping under test.
     */
    abstract protected static function userMapping(): string;

    /**
     * The `user_class` of the mapping, and extra entity paths (the host user entity of the relation mapping).
     *
     * @return array{0: class-string, 1: list<string>}
     */
    abstract protected static function userClass(): array;

    /**
     * The security user of a vault user id with this identifier (persisting it when the mapping needs a row).
     */
    abstract protected function userOf(int|string $id, string $identifier): UserInterface;

    /**
     * The value stored in `creator_id` for that user (the entity, or the id string).
     */
    abstract protected function ownerValue(int|string $id): object|string;

    protected function setUp(): void
    {
        $this->vaultEvents = new EventDispatcher();
        $this->tokens      = new TokenStorage();
        $this->scopeCache  = new RequestScopedScopeAccessCache(new class($this) implements VaultScopeAccessResolverInterface {
            public function __construct(private readonly VaultItemSearchTestCase $test)
            {
            }

            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return $this->test->scopeAccess((string) UserIdResolver::getId($user), $scope);
            }
        });
    }

    /**
     * @internal the test scope resolver
     */
    public function scopeAccess(string $userId, string $scope): ?VaultScopeAccess
    {
        return $this->members[$userId][$scope] ?? null;
    }

    /**
     * @internal the test team resolver
     *
     * @return list<string>
     */
    public function teamsOf(string $userId): array
    {
        return $this->teamIds[$userId] ?? [];
    }

    protected static function em(): EntityManagerInterface
    {
        if (self::$em instanceof EntityManagerInterface && self::$em->isOpen()) {
            return self::$em;
        }

        [$userClass, $paths] = static::userClass();
        $config              = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity', ...$paths], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-vtsk-proxies');
            $config->setProxyNamespace('NowoVtskProxies');
        }
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new VaultMetadataListener('vault_items', 'vault_folders', 'vault_grants', 'vault_tags', 'vault_item_tag', 'vault_settings', 'vault_extension_tokens', $userClass, static::userMapping()));

        self::$counter = new QueryCounter();
        $dbal          = (new DbalConfiguration())->setMiddlewares([new QueryCountingMiddleware(self::$counter)]);
        $url           = getenv('VAULT_TEST_DATABASE_URL');
        $params        = is_string($url) && $url !== ''
            ? (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url)
            : ['driver' => 'pdo_sqlite', 'memory' => true];
        $em = new EntityManager(DriverManager::getConnection($params, $dbal), $config, $events);

        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return self::$em = $em;
    }

    public static function tearDownAfterClass(): void
    {
        self::$em?->getConnection()->close();
        self::$em      = null;
        self::$counter = null;
    }

    protected function definition(): EntitySearchDefinition
    {
        return $this->searchDefinition();
    }

    protected function searchDefinition(?VaultRuntimeConfigProvider $runtimeConfig = null, int $maxCandidates = EntitySearchDefinition::DEFAULT_MAX_CANDIDATES, ?TranslatorInterface $translator = new IdentityTranslator()): VaultItemSearchDefinition
    {
        $em    = self::em();
        $users = new DoctrineVaultUserReferenceResolver(static::userClass()[0], new VaultUserClassPolicy(static::userClass()[0], [], static::userMapping()), static::userMapping());
        $teams = $this->teamResolver();
        $guard = new VaultAccessGuard(new DoctrineOrmVaultGrantRepository($em, users: $users), $teams, $this->vaultEvents, $this->scopeCache, null, $users);

        return new VaultItemSearchDefinition(
            $em,
            new VaultSearchViewers($users, $this->tokens),
            $guard,
            $this->accessChecker(),
            $teams,
            self::urls(),
            eventDispatcher: $this->vaultEvents,
            translator: $translator,
            runtimeConfig: $runtimeConfig,
            scopesEnabled: $this->scopesEnabled,
            maxCandidates: $maxCandidates,
        );
    }

    protected static function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('nowo_vault_item_view', new Route('/tools/vault/items/{id}'));
        $routes->add('nowo_vault_items', new Route('/tools/vault/items'));
        $routes->add('host_item_view', new Route('/host/secrets/{id}'));
        $routes->add('host_items', new Route('/host/secrets'));

        return new UrlGenerator($routes, new RequestContext());
    }

    /**
     * A runtime configuration provider whose item routes are the host's.
     */
    protected static function hostRoutes(): VaultRuntimeConfigProvider
    {
        return new VaultRuntimeConfigProvider(
            VaultRuntimeConfigFactory::baseline(['routes' => ['item_view' => ['name' => 'host_item_view'], 'items' => ['name' => 'host_items']]]),
            false,
            new class implements VaultSettingsRepositoryInterface {
                public function findByScope(string $scope = VaultSettings::DEFAULT_SCOPE): ?VaultSettings
                {
                    return null;
                }

                public function save(VaultSettings $settings): void
                {
                }
            },
            new ArrayAdapter(),
        );
    }

    private function accessChecker(): VaultAccessCheckerInterface
    {
        return new class($this) implements VaultAccessCheckerInterface {
            public function __construct(private readonly VaultItemSearchTestCase $test)
            {
            }

            public function canAccess(?UserInterface $user = null): bool
            {
                return $user instanceof UserInterface;
            }

            public function canCreate(?UserInterface $user = null): bool
            {
                return false;
            }

            public function canList(?UserInterface $user = null): bool
            {
                return $user instanceof UserInterface && $this->test->listAllowed();
            }

            public function canRevoke(?UserInterface $user = null): bool
            {
                return false;
            }
        };
    }

    /**
     * @internal the test access checker
     */
    public function listAllowed(): bool
    {
        return $this->listAllowed;
    }

    private function teamResolver(): VaultTeamMembershipResolverInterface
    {
        return new class($this) implements VaultTeamMembershipResolverInterface {
            public function __construct(private readonly VaultItemSearchTestCase $test)
            {
            }

            public function getTeamIdsForUser(UserInterface $user): array
            {
                return $this->test->teamsOf((string) UserIdResolver::getId($user));
            }
        };
    }

    protected function queryCounter(): QueryCounter
    {
        self::em();

        return self::$counter ?? new QueryCounter();
    }

    protected function sensitiveTitles(): bool
    {
        return true;
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        // A new request: the request-scoped scope memo is cleared, as VaultRequestBoundaryListener does.
        $this->scopeCache->reset();
        $this->listAllowed = true;
        if ($tenant === null) {
            $this->tokens->setToken(null);

            return;
        }
        $user = $this->userOf($tenant, $userIdentifier);
        $this->tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    protected function disableFeature(): void
    {
        $this->listAllowed = false;
    }

    protected function seed(int|string $tenant, string $text): int|string
    {
        return $this->persistItem(new VaultItem(VaultItemType::Login, $text, $this->ownerValue($tenant), 'ciphertext'));
    }

    protected function seedHidden(int|string $tenant, string $text): int|string
    {
        return $this->persistItem(VaultItem::forScope(VaultItemType::Login, $text, self::HIDDEN_SCOPE, null, 'ciphertext'));
    }

    protected function seedArchived(int|string $tenant, string $text): int|string
    {
        $item = new VaultItem(VaultItemType::Login, $text, $this->ownerValue($tenant), 'ciphertext');
        $item->markDeleted();

        return $this->persistItem($item);
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/tools/vault/items/' . $id, VaultItemSearchDefinition::GROUP, 'nowo_vault_item_view', ['id' => (string) $id]);
    }

    /**
     * Persists the item and gives it a strictly later `updated_at` than every earlier seed (the order is
     * updated_at DESC, id ASC: rows seeded in the same second must not tie on random UUIDs).
     */
    protected function persistItem(VaultItem $item): string
    {
        $em = self::em();
        $em->persist($item);
        $em->flush();
        $em->getConnection()->update('vault_items', ['updated_at' => new DateTimeImmutable('2026-01-01 00:00:00 +' . ++self::$clockTick . ' seconds')], ['id' => $item->getId()], ['updated_at' => 'datetime_immutable']);
        $em->refresh($item);

        return $item->getId();
    }

    protected function persistFolder(VaultFolder $folder): VaultFolder
    {
        self::em()->persist($folder);
        self::em()->flush();

        return $folder;
    }

    protected function grant(VaultResourceType $type, string $resourceId, GranteeType $granteeType, string $granteeId, ?DateTimeImmutable $expiresAt = null): void
    {
        $grant = new VaultGrant($type, $resourceId, $granteeType, $granteeId, VaultPermission::Read, null, $expiresAt);
        self::em()->persist($grant);
        self::em()->flush();
    }
}
