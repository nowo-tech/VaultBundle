<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Equivalence;

use Closure;
use DateTimeImmutable;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Port\VaultAccessCheckerGate;
use Nowo\VaultBundle\Port\VaultScopeAccessGate;
use Nowo\VaultBundle\Port\VaultTeamMembershipGate;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use Nowo\VaultBundle\Tests\Stub\InMemoryGrantRepository;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\Legacy\AllowAllVaultAccessCheckerV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\ConfigurableVaultAccessCheckerV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\DoctrineVaultUserReferenceResolverV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\RequestScopedScopeAccessCacheV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\VaultAccessGuardV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\VaultScopeAccessResolverInterfaceV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\VaultTeamMembershipResolverInterfaceV1180;
use Nowo\VaultBundle\Tests\Support\Legacy\VaultUserClassPolicyV1180;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_diff_assoc;
use function array_keys;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function strtolower;
use function var_export;

/**
 * 1.19.0 equivalence (REQ-ARCH-001 rule 3), kept in 2.0: every access decision of the vault (ownership, user and team
 * grants on items and folders, folder grants across owners, expired grants, scope membership and baselines, read-only
 * and access-check listeners, the permission and scope-view maps, and the role-based feature checks) is the same as the
 * literal v1.18.0 copies in tests/Support/Legacy (asked through test copies of the removed 1.x interfaces), with a host
 * on the framework-free ports or the built-ins: removing the deprecated interfaces in 2.0 changed no decision. Relation
 * and id user mappings are both covered, as are foreign user classes, ids that differ only by case, and users without an
 * id.
 *
 * A mutation check of the decision path runs this test: scripts/mutation-check-access-matrix.php.
 */
final class AccessDecisionEquivalenceMatrixTest extends TestCase
{
    private const NOW = '2026-11-12 12:00:00';

    /** @var array<string, array<string, list<string>>> host team membership: user id => team ids */
    private const TEAMS = ['u-carol' => ['team-1'], 'u-erin' => ['team-2', 'team-1'], 'U-ALICE' => ['team-1']];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function wirings(): iterable
    {
        foreach ([VaultUserMapping::RELATION, VaultUserMapping::ID] as $mapping) {
            foreach (['policy', 'built-in'] as $wiring) {
                yield $mapping . ' / ' . $wiring => [$mapping, $wiring];
            }
        }
    }

    #[Test]
    #[DataProvider('wirings')]
    public function everyDecisionMatchesTheV1180Reference(string $mapping, string $wiring): void
    {
        $reference = $this->decisions($mapping, $wiring, true);
        $current   = $this->decisions($mapping, $wiring, false);

        self::assertGreaterThan(400, count($reference));
        self::assertSame([], array_diff_assoc($reference, $current), 'decisions that differ from v1.18.0: ' . implode(', ', array_keys(array_diff_assoc($reference, $current))));
        self::assertSame($reference, $current);
    }

    /**
     * Audit NJ-1: a reference given by id alone never decides by itself. The role checker denies every feature to it
     * (whatever roles it claims, even with the token user's id), and the vault resolves its id through the user
     * locator: the answer is the located user (its own roles), or nothing for an unknown id or an answer for another id.
     */
    #[Test]
    public function forgedReferencesNeverWidenAnyDecision(): void
    {
        $token    = new TestUser('u-token', 'token@example.com', ['ROLE_VAULT_USER']);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($token);
        $security->method('isGranted')->willReturn(true);
        $security->method('isGrantedForUser')->willReturn(true);
        $provider = new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(['security' => ['admin_roles' => ['ROLE_VAULT_ADMIN'], 'access_roles' => [], 'create_roles' => [], 'list_roles' => [], 'delete_roles' => []]]), false, $this->createMock(VaultSettingsRepositoryInterface::class), new ArrayAdapter());
        $gate     = new VaultAccessCheckerGate(new ConfigurableVaultAccessChecker($security, $provider));
        $policy   = new ConfigurableVaultAccessChecker($security, $provider);
        foreach (['u-token', 'u-alice', 'U-ALICE'] as $id) {
            $forged = VaultUserRef::ofId($id, 'token@example.com', ['ROLE_VAULT_ADMIN']);
            foreach (['allowsAccess', 'allowsCreate', 'allowsList', 'allowsRevoke'] as $feature) {
                self::assertFalse($policy->{$feature}($forged), $feature . ' / ' . $id);
            }
        }
        self::assertTrue($gate->canAccess(), 'the request token itself is still checked');

        $users = $this->users();
        $byId  = [];
        foreach ($users as $user) {
            if (!$user instanceof OtherDomainUser) {
                $byId[(string) UserIdResolver::getId($user)] = $user;
            }
        }
        $directory = new class($byId) implements VaultUserDirectoryInterface {
            /** @param array<string, UserInterface> $users */
            public function __construct(private readonly array $users)
            {
            }

            public function findUser(string $id): ?VaultUserRef
            {
                // A sloppy host lookup: case-insensitive.
                foreach ($this->users as $userId => $user) {
                    if (strtolower((string) $userId) === strtolower($id)) {
                        return VaultUserRefs::fromUser($user);
                    }
                }

                return null;
            }
        };
        $resolver = new VaultUserRefResolver(new VaultUserLocatorGate($directory));
        foreach ($users as $name => $user) {
            $id = UserIdResolver::getId($user);
            if ($id === null || $user instanceof OtherDomainUser) {
                continue;
            }
            $resolved = $resolver->resolve(VaultUserRef::ofId($id, 'forged', ['ROLE_VAULT_ADMIN']));
            // u-alice and U-ALICE collide in the sloppy lookup: only the byte-equal answer is kept.
            if ($resolved instanceof UserInterface) {
                self::assertSame($user, $resolved, $name);
                self::assertNotContains('ROLE_VAULT_ADMIN', $resolved->getRoles());
            }
        }
        self::assertNull($resolver->resolve(VaultUserRef::ofId('u-nobody', 'x', ['ROLE_VAULT_ADMIN'])));
        self::assertNull($resolver->resolve(VaultUserRef::ofId('U-BOB')), 'an answer for another id (u-bob) is refused');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function checkerWirings(): iterable
    {
        yield 'policy' => ['policy'];
    }

    #[Test]
    #[DataProvider('checkerWirings')]
    public function everyFeatureCheckMatchesTheV1180Reference(string $wiring): void
    {
        $token = new TestUser('u-token', 'token@example.com', ['ROLE_VAULT_USER']);
        $users = [
            'anonymous'  => null,
            'token user' => $token,
            'other user' => new TestUser('u-other', 'other@example.com', ['ROLE_VAULT_ADMIN']),
            'creator'    => new TestUser('u-creator', 'creator@example.com', ['ROLE_VAULT_CREATE', 'ROLE_VAULT_USER']),
            'no roles'   => new TestUser('u-none', 'none@example.com', []),
            'same id'    => new TestUser('u-token', 'token@example.com', ['ROLE_VAULT_DELETE']),
        ];
        $roleSets = [
            'admin only'  => ['admin_roles' => ['ROLE_VAULT_ADMIN'], 'access_roles' => [], 'create_roles' => [], 'list_roles' => [], 'delete_roles' => []],
            'split roles' => ['admin_roles' => ['ROLE_VAULT_ADMIN'], 'access_roles' => ['ROLE_VAULT_USER'], 'create_roles' => ['ROLE_VAULT_CREATE'], 'list_roles' => ['ROLE_VAULT_USER'], 'delete_roles' => ['ROLE_VAULT_DELETE']],
            'delete only' => ['admin_roles' => [], 'access_roles' => [], 'create_roles' => [], 'list_roles' => [], 'delete_roles' => ['ROLE_VAULT_DELETE']],
            // A role only the request token carries (e.g. added by an authenticator): the token user is checked through
            // the token, any other user through its own roles.
            'token role' => ['admin_roles' => [], 'access_roles' => ['ROLE_TOKEN_ONLY'], 'create_roles' => ['ROLE_VAULT_CREATE'], 'list_roles' => [], 'delete_roles' => []],
        ];

        $reference = [];
        $current   = [];
        foreach ($roleSets as $setName => $roles) {
            $security = $this->createMock(Security::class);
            $security->method('getUser')->willReturn($token);
            $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, [...$token->getRoles(), 'ROLE_TOKEN_ONLY'], true));
            $security->method('isGrantedForUser')->willReturnCallback(static fn (UserInterface $user, string $role): bool => in_array($role, $user->getRoles(), true));
            $provider = new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(['security' => $roles]), false, $this->createMock(VaultSettingsRepositoryInterface::class), new ArrayAdapter());

            $legacyChecker = new ConfigurableVaultAccessCheckerV1180($security, $provider);
            $builtIn       = new ConfigurableVaultAccessChecker($security, $provider);
            $gates         = [
                'configurable' => new VaultAccessCheckerGate($builtIn),
                'allow all'    => new VaultAccessCheckerGate(new AllowAllVaultAccessChecker()),
            ];
            $references = ['configurable' => $legacyChecker, 'allow all' => new AllowAllVaultAccessCheckerV1180()];

            foreach ($users as $userName => $user) {
                foreach ($gates as $kind => $gate) {
                    $old = $references[$kind];
                    foreach (['canAccess', 'canCreate', 'canList', 'canRevoke'] as $feature) {
                        $key             = sprintf('%s / %s / %s / %s', $setName, $kind, $userName, $feature);
                        $reference[$key] = $old->{$feature}($user);
                        $current[$key]   = $gate->{$feature}($user);
                    }
                }
            }
        }

        self::assertContains(true, $reference);
        self::assertContains(false, $reference);
        self::assertSame([], array_diff_assoc($reference, $current), 'feature checks that differ from v1.18.0: ' . implode(', ', array_keys(array_diff_assoc($reference, $current))));
    }

    /**
     * Every decision of one wiring, keyed by a readable label.
     *
     * @return array<string, string>
     */
    private function decisions(string $mapping, string $wiring, bool $reference): array
    {
        $byId   = $mapping === VaultUserMapping::ID;
        $users  = $this->users();
        $world  = $this->world($users, $byId);
        $grants = $world['grants'];
        $labels = $world['labels'];
        $clock  = new class(new DateTimeImmutable(self::NOW)) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $resolver = $reference
            ? new DoctrineVaultUserReferenceResolverV1180(TestUser::class, new VaultUserClassPolicyV1180(TestUser::class, [], $mapping), $mapping)
            : new DoctrineVaultUserReferenceResolver(TestUser::class, new VaultUserClassPolicy(TestUser::class, [], $mapping), $mapping);

        [$legacyTeam, $newTeam, $legacyScope, $newScope] = $this->hosts();
        if ($reference) {
            [$team, $scope] = match ($wiring) {
                'built-in' => [$this->nullLegacyTeams(), $this->nullLegacyScopes()],
                default    => [$legacyTeam, $legacyScope],
            };
            $guard   = new VaultAccessGuardV1180($grants, $team, $this->dispatcher($labels), new RequestScopedScopeAccessCacheV1180($scope), $clock, $resolver);
            $teamIds = $team;
        } else {
            [$teamGate, $scopeGate] = match ($wiring) {
                'policy' => [new VaultTeamMembershipGate($newTeam), new VaultScopeAccessGate($newScope)],
                default  => [new VaultTeamMembershipGate(new NullVaultTeamMembershipResolver()), new VaultScopeAccessGate(new NullVaultScopeAccessResolver())],
            };
            $guard   = new VaultAccessGuard($grants, $teamGate, $this->dispatcher($labels), new RequestScopedScopeAccessCache($scopeGate), $clock, $resolver);
            $teamIds = $teamGate;
        }

        $out = [];
        foreach ($users as $userName => $user) {
            if ($user->getId() !== null) {
                $out['teams / ' . $userName] = implode(',', $teamIds->getTeamIdsForUser($user));
            }
            foreach ($world['items'] as $itemName => $item) {
                foreach (VaultAccessAction::cases() as $action) {
                    $out[sprintf('item %s / %s / %s', $itemName, $userName, $action->value)] = var_export($guard->canAccessItem($user, $item, $action), true);
                }
                $out[sprintf('read-only %s / %s', $itemName, $userName)] = var_export($guard->isItemReadOnly($user, $item), true);
            }
            foreach ($world['folders'] as $folderName => $folder) {
                foreach (VaultAccessAction::cases() as $action) {
                    $out[sprintf('folder %s / %s / %s', $folderName, $userName, $action->value)] = var_export($guard->canAccessFolder($user, $folder, $action), true);
                }
            }
            foreach ($guard->resolvePermissionMap($user, array_values($world['items'])) as $itemId => $permission) {
                $out[sprintf('permission map %s / %s', $labels[$itemId], $userName)] = $permission?->value ?? 'none';
            }
            foreach ($guard->resolveScopeViewMap($user, 'org-1', $world['scopeView']) as $itemId => $view) {
                $out[sprintf('scope view %s / %s', $labels[(string) $itemId], $userName)] = var_export($view, true);
            }
            foreach (['org-1', 'org-2', 'org-3', 'Bad Scope!'] as $scope) {
                $access                                                  = $guard->resolveScopeAccess($user, $scope);
                $out[sprintf('scope access %s / %s', $scope, $userName)] = $access instanceof VaultScopeAccess
                    ? sprintf('%s,%d%d%d', $access->baseline?->value ?? 'none', $access->catalogue, $access->health, $access->create)
                    : 'not a member';
            }
        }

        return $out;
    }

    /**
     * @return array<string, UserInterface>
     */
    private function users(): array
    {
        return [
            'alice'          => new TestUser('u-alice', 'alice@example.com'),
            'bob'            => new TestUser('u-bob', 'bob@example.com'),
            'carol (team 1)' => new TestUser('u-carol', 'carol@example.com'),
            'dave (member)'  => new TestUser('u-dave', 'dave@example.com'),
            'erin (writer)'  => new TestUser('u-erin', 'erin@example.com'),
            'frank'          => new TestUser('u-frank', 'frank@example.com'),
            'ALICE (case)'   => new TestUser('U-ALICE', 'alice2@example.com'),
            'foreign alice'  => new OtherDomainUser('u-alice', 'alice@other.example'),
            'no id'          => new class implements UserInterface {
                public function getId(): ?string
                {
                    return null;
                }

                public function getUserIdentifier(): string
                {
                    return 'no-id@example.com';
                }

                public function getRoles(): array
                {
                    return ['ROLE_USER'];
                }

                public function eraseCredentials(): void
                {
                }
            },
        ];
    }

    /**
     * Items, folders and grants: the same rows in both chains.
     *
     * @param array<string, UserInterface> $users
     *
     * @return array{items: array<string, VaultItem>, folders: array<string, VaultFolder>, grants: InMemoryGrantRepository, labels: array<string, string>, scopeView: array<string, string|null>}
     */
    private function world(array $users, bool $byId): array
    {
        $owner = static fn (string $name): object|string => $byId ? (string) UserIdResolver::getId($users[$name]) : $users[$name];

        $folders = [
            'alice folder' => new VaultFolder('Work', $owner('alice')),
            'bob folder'   => new VaultFolder('Bob', $owner('bob')),
            'org-1 folder' => VaultFolder::forScope('Payments', 'org-1', null),
            'org-2 folder' => VaultFolder::forScope('Ops', 'org-2', null),
        ];
        $items = [
            'alice personal'        => new VaultItem(VaultItemType::Login, 'A', $owner('alice'), 'c'),
            'alice in her folder'   => new VaultItem(VaultItemType::Login, 'B', $owner('alice'), 'c', $folders['alice folder']),
            'alice in bob folder'   => new VaultItem(VaultItemType::SecureNote, 'C', $owner('alice'), 'c', $folders['bob folder']),
            'bob personal'          => new VaultItem(VaultItemType::Login, 'D', $owner('bob'), 'c'),
            'org-1 item'            => VaultItem::forScope(VaultItemType::Login, 'E', 'org-1', null, 'c'),
            'org-1 item in folder'  => VaultItem::forScope(VaultItemType::Login, 'F', 'org-1', null, 'c', $folders['org-1 folder']),
            'org-1 item in org-2 f' => VaultItem::forScope(VaultItemType::Login, 'G', 'org-1', null, 'c', $folders['org-2 folder']),
            'org-2 item'            => VaultItem::forScope(VaultItemType::Login, 'H', 'org-2', null, 'c', $folders['org-2 folder']),
        ];

        $now    = new DateTimeImmutable(self::NOW);
        $grants = new InMemoryGrantRepository($now);
        $grant  = static function (VaultResourceType $type, string $id, GranteeType $granteeType, string $grantee, VaultPermission $permission, ?string $expires = null) use ($grants): void {
            $grants->save(new VaultGrant($type, $id, $granteeType, $grantee, $permission, null, $expires !== null ? new DateTimeImmutable($expires) : null));
        };
        $grant(VaultResourceType::Item, $items['alice personal']->getId(), GranteeType::User, 'u-bob', VaultPermission::Read);
        $grant(VaultResourceType::Item, $items['alice personal']->getId(), GranteeType::Team, 'team-1', VaultPermission::Write);
        $grant(VaultResourceType::Item, $items['alice personal']->getId(), GranteeType::User, 'u-erin', VaultPermission::Admin, '2026-11-12 11:00:00');
        $grant(VaultResourceType::Item, $items['alice personal']->getId(), GranteeType::User, 'u-frank', VaultPermission::Write, '2026-11-12 13:00:00');
        $grant(VaultResourceType::Folder, $folders['alice folder']->getId(), GranteeType::User, 'u-dave', VaultPermission::Admin);
        $grant(VaultResourceType::Folder, $folders['bob folder']->getId(), GranteeType::User, 'u-carol', VaultPermission::Admin);
        $grant(VaultResourceType::Item, $items['bob personal']->getId(), GranteeType::User, 'U-ALICE', VaultPermission::Admin);
        $grant(VaultResourceType::Item, $items['org-1 item']->getId(), GranteeType::User, 'u-frank', VaultPermission::Admin);
        $grant(VaultResourceType::Item, $items['org-1 item']->getId(), GranteeType::User, 'u-dave', VaultPermission::Write);
        $grant(VaultResourceType::Folder, $folders['org-1 folder']->getId(), GranteeType::Team, 'team-2', VaultPermission::Admin);
        $grant(VaultResourceType::Folder, $folders['org-2 folder']->getId(), GranteeType::User, 'u-erin', VaultPermission::Admin);
        $grant(VaultResourceType::Item, $items['org-2 item']->getId(), GranteeType::Team, 'team-1', VaultPermission::Read);

        $labels = [];
        foreach ($items as $name => $item) {
            $labels[$item->getId()] = $name;
        }

        return [
            'items'     => $items,
            'folders'   => $folders,
            'grants'    => $grants,
            'labels'    => $labels,
            'scopeView' => [
                $items['org-1 item']->getId()           => null,
                $items['org-1 item in folder']->getId() => $folders['org-1 folder']->getId(),
                $items['bob personal']->getId()         => null,
            ],
        ];
    }

    /**
     * Listeners of both chains: a read-only mark, a veto and a listener grant.
     *
     * @param array<string, string> $labels
     */
    private function dispatcher(array $labels): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_READ_ONLY_RESOLVE, static function (VaultItemReadOnlyEvent $event) use ($labels): void {
            if ($event->getUserId() === 'u-carol' && ($labels[$event->getItem()->getId()] ?? '') === 'alice personal') {
                $event->markReadOnly();
            }
        });
        $dispatcher->addListener(VaultEvents::ITEM_ACCESS_CHECK, static function (VaultItemAccessCheckEvent $event) use ($labels): void {
            $label = $labels[$event->getItem()->getId()] ?? '';
            $user  = $event->getUserId();
            if ($user === 'u-dave' && $label === 'org-1 item in folder') {
                $event->deny();
            }
            if ($user === 'u-frank' && $label === 'bob personal' && $event->getAction() === VaultAccessAction::View) {
                $event->grant();
            }
            if ($user === 'u-erin' && $label === 'org-1 item') {
                $event->markReadOnly();
            }
        });

        return $dispatcher;
    }

    /**
     * The v1.18.0 built-ins (no teams, no scope), behind the test copies of the removed interfaces.
     */
    private function nullLegacyTeams(): VaultTeamMembershipResolverInterfaceV1180
    {
        return new class implements VaultTeamMembershipResolverInterfaceV1180 {
            public function getTeamIdsForUser(UserInterface $user): array
            {
                return [];
            }
        };
    }

    private function nullLegacyScopes(): VaultScopeAccessResolverInterfaceV1180
    {
        return new class implements VaultScopeAccessResolverInterfaceV1180 {
            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return null;
            }
        };
    }

    /**
     * The same host answers behind the v1.18.0 interfaces (reference chain) and the framework-free ports.
     *
     * @return array{VaultTeamMembershipResolverInterfaceV1180, VaultTeamMembershipProviderInterface, VaultScopeAccessResolverInterfaceV1180, VaultScopeAccessProviderInterface}
     */
    private function hosts(): array
    {
        $teams  = static fn (?string $id): array => self::TEAMS[$id ?? ''] ?? [];
        $scopes = (static fn (?string $id, string $scope): ?VaultScopeAccess => match ([$id, $scope]) {
            ['u-dave', 'org-1']  => new VaultScopeAccess(VaultPermission::Read),
            ['u-erin', 'org-1']  => new VaultScopeAccess(VaultPermission::Write, true, false, true),
            ['u-carol', 'org-1'] => new VaultScopeAccess(null, true),
            ['u-alice', 'org-1'] => new VaultScopeAccess(VaultPermission::Admin, true, true, true),
            ['u-erin', 'org-2']  => new VaultScopeAccess(null),
            ['u-carol', 'org-2'] => new VaultScopeAccess(VaultPermission::Read),
            ['U-ALICE', 'org-2'] => new VaultScopeAccess(VaultPermission::Admin),
            default              => null,
        });

        $legacyTeam = new class($teams) implements VaultTeamMembershipResolverInterfaceV1180 {
            public function __construct(private readonly Closure $teams)
            {
            }

            public function getTeamIdsForUser(UserInterface $user): array
            {
                return ($this->teams)(UserIdResolver::getId($user));
            }
        };
        $newTeam = new class($teams) implements VaultTeamMembershipProviderInterface {
            public function __construct(private readonly Closure $teams)
            {
            }

            public function teamIdsOf(VaultUserRef $user): array
            {
                return ($this->teams)($user->id);
            }
        };
        $legacyScope = new class($scopes) implements VaultScopeAccessResolverInterfaceV1180 {
            public function __construct(private readonly Closure $scopes)
            {
            }

            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return ($this->scopes)(UserIdResolver::getId($user), $scope);
            }
        };
        $newScope = new class($scopes) implements VaultScopeAccessProviderInterface {
            public function __construct(private readonly Closure $scopes)
            {
            }

            public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
            {
                return ($this->scopes)(is_string($user->id) ? $user->id : null, $scope);
            }
        };

        return [$legacyTeam, $newTeam, $legacyScope, $newScope];
    }
}
