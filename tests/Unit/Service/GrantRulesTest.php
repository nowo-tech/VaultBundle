<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Closure;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Nowo\VaultBundle\Event\VaultGrantPolicyCheckEvent;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultGrantListResolver;
use Nowo\VaultBundle\Service\VaultGrantResult;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Tests\Stub\InMemoryGrantRepository;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.16.0 grant rules (contract §4.1 refusal codes, §4.3 upsert table, races, events).
 */
final class GrantRulesTest extends TestCase
{
    private TestUser $owner;
    private TestUser $grantee;
    private VaultItem $item;
    private VaultItem $scopeItem;
    private VaultFolder $folder;
    private InMemoryGrantRepository $grants;
    private EventDispatcher $dispatcher;
    private MockClock $clock;
    private RecordingLogger $logger;

    /** @var list<string> */
    private array $events = [];

    /** @var array<string, array<string, VaultScopeAccess>> user id => scope => access */
    private array $members = [];

    protected function setUp(): void
    {
        $this->owner      = new TestUser('1', 'owner@example.com');
        $this->grantee    = new TestUser('2', 'grantee@example.com');
        $this->item       = new VaultItem(VaultItemType::Login, 'Mail', $this->owner, 'c');
        $this->scopeItem  = VaultItem::forScope(VaultItemType::Login, 'Stripe', 'org-1', $this->owner, 'c');
        $this->folder     = new VaultFolder('Mine', $this->owner);
        $this->clock      = new MockClock('2026-11-05 12:00:00');
        $this->grants     = new InMemoryGrantRepository($this->clock->now());
        $this->dispatcher = new EventDispatcher();
        $this->logger     = new RecordingLogger();
        $this->members    = ['1' => ['org-1' => new VaultScopeAccess(VaultPermission::Admin, true, true, true)]];
        foreach ([VaultEvents::GRANT_CREATED, VaultEvents::GRANT_UPDATED, VaultEvents::GRANT_REVOKED] as $name) {
            $this->dispatcher->addListener($name, function (VaultGrantChangeEvent $event) use ($name): void {
                $this->events[] = $name;
            });
        }
    }

    public function testCreateThenPermanentUpdate(): void
    {
        $service = $this->service();

        self::assertSame(VaultGrantResult::Created, $this->grantAs($service, VaultPermission::Read));
        self::assertSame(VaultGrantResult::Updated, $this->grantAs($service, VaultPermission::Write));
        self::assertSame([VaultEvents::GRANT_CREATED, VaultEvents::GRANT_UPDATED], $this->events);
        self::assertSame(VaultPermission::Write, $this->onlyGrant()->getPermission());
        self::assertSame('1', $this->logger->records[0]['context']['actor_id']);
    }

    public function testTemporaryCoveredByPermanentIsUnchanged(): void
    {
        $service = $this->service(['max_permission' => 'write']);
        $this->grantAs($service, VaultPermission::Write);
        $this->events = [];

        self::assertSame(VaultGrantResult::Unchanged, $this->grantAs($service, VaultPermission::Read, '+1 day'));
        self::assertSame(VaultGrantResult::Unchanged, $this->grantAs($service, VaultPermission::Write, '+1 day'));
        self::assertSame([], $this->events);
        self::assertFalse($this->onlyGrant()->isTemporary());
    }

    public function testTemporaryUpgradeOfPermanentIsRefused(): void
    {
        $service = $this->service(['max_permission' => 'write']);
        $this->grantAs($service, VaultPermission::Read);

        $this->assertRefused(VaultGrantRefusedException::WOULD_REPLACE_PERMANENT, fn (): VaultGrantResult => $this->grantAs($service, VaultPermission::Write, '+1 day'));
        self::assertFalse($this->onlyGrant()->isTemporary());
    }

    public function testTemporaryIsExtendedShortenedAndMadePermanent(): void
    {
        $service = $this->service(['max_permission' => 'write']);
        $this->grantAs($service, VaultPermission::Read, '+1 day');
        self::assertSame(VaultGrantResult::Updated, $this->grantAs($service, VaultPermission::Read, '+3 days'));
        self::assertEquals($this->clock->now()->modify('+3 days'), $this->onlyGrant()->getExpiresAt());
        self::assertSame(VaultGrantResult::Updated, $this->grantAs($service, VaultPermission::Write, '+1 hour'));
        self::assertSame(VaultPermission::Write, $this->onlyGrant()->getPermission());

        // An expired, not yet purged temporary grant is replaced too.
        $this->clock->modify('+2 hours');
        self::assertSame(VaultGrantResult::Updated, $this->grantAs($service, VaultPermission::Read, '+1 day'));
        self::assertSame(VaultGrantResult::Updated, $this->grantAs($service, VaultPermission::Read));
        self::assertFalse($this->onlyGrant()->isTemporary());
        self::assertSame([VaultEvents::GRANT_CREATED, VaultEvents::GRANT_UPDATED, VaultEvents::GRANT_UPDATED, VaultEvents::GRANT_UPDATED, VaultEvents::GRANT_UPDATED], $this->events);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function outOfRange(): iterable
    {
        yield 'below min_ttl' => ['+4 minutes'];
        yield 'past' => ['-1 hour'];
        yield 'above max_ttl' => ['+31 days'];
    }

    #[DataProvider('outOfRange')]
    public function testExpiryOutOfRange(string $expiry): void
    {
        $this->assertRefused(VaultGrantRefusedException::EXPIRY_OUT_OF_RANGE, fn (): VaultGrantResult => $this->grantAs($this->service(), VaultPermission::Read, $expiry));
    }

    public function testTemporaryPermissionCap(): void
    {
        $this->assertRefused(VaultGrantRefusedException::TEMPORARY_PERMISSION_TOO_HIGH, fn (): VaultGrantResult => $this->grantAs($this->service(), VaultPermission::Write, '+1 day'));
        $this->assertRefused(VaultGrantRefusedException::TEMPORARY_PERMISSION_TOO_HIGH, fn (): VaultGrantResult => $this->grantAs($this->service(['max_permission' => 'write']), VaultPermission::Admin, '+1 day'));
        self::assertSame(VaultGrantResult::Created, $this->grantAs($this->service(['max_permission' => 'write']), VaultPermission::Write, '+1 day'));
    }

    public function testActorWhoCannotShareIsRefused(): void
    {
        $service = $this->service();
        $this->assertRefused(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, fn (): VaultGrantResult => $service->grantAs($this->grantee, VaultResourceType::Item, $this->item->getId(), GranteeType::User, '3', VaultPermission::Read));
        $this->assertRefused(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, fn (): VaultGrantResult => $service->grantAs($this->owner, VaultResourceType::Item, 'unknown', GranteeType::User, '3', VaultPermission::Read));
        $this->item->markDeleted();
        $this->assertRefused(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, fn (): VaultGrantResult => $this->grantAs($service, VaultPermission::Read));
    }

    public function testActorLosesShareBetweenPageLoadAndSubmit(): void
    {
        $service = $this->service();
        $admin   = new TestUser('5');
        $this->grants->save(new VaultGrant(VaultResourceType::Item, $this->item->getId(), GranteeType::User, '5', VaultPermission::Admin, $this->owner));
        self::assertSame(VaultGrantResult::Created, $service->grantAs($admin, VaultResourceType::Item, $this->item->getId(), GranteeType::User, '6', VaultPermission::Read));

        // Demoted to read meanwhile: the next grant is refused at the call.
        $this->grants->findOne(VaultResourceType::Item, $this->item->getId(), GranteeType::User, '5')?->setPermission(VaultPermission::Read);
        $this->assertRefused(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, fn (): VaultGrantResult => $service->grantAs($admin, VaultResourceType::Item, $this->item->getId(), GranteeType::User, '7', VaultPermission::Read));
    }

    public function testSelfGrantIsRefused(): void
    {
        $this->assertRefused(VaultGrantRefusedException::SELF_GRANT, fn (): VaultGrantResult => $this->service()->grantAs($this->owner, VaultResourceType::Item, $this->item->getId(), GranteeType::User, '1', VaultPermission::Admin));
    }

    public function testEmptyGranteeListFailsClosed(): void
    {
        $this->assertRefused(VaultGrantRefusedException::GRANTEE_NOT_ALLOWED, fn (): VaultGrantResult => $this->grantAs($this->service(allowUnlisted: false), VaultPermission::Read));
    }

    public function testListedGranteeIsAllowedWithoutTheUnsafeSwitch(): void
    {
        $this->dispatcher->addListener(VaultEvents::GRANT_LIST_QUERY, static function (VaultGrantListQueryEvent $event): void {
            $event->addGrantee(new VaultGranteeChoice(GranteeType::User, '2', 'Grantee'));
        });

        self::assertSame(VaultGrantResult::Created, $this->grantAs($this->service(allowUnlisted: false), VaultPermission::Read));
    }

    public function testScopeGranteeMustBeAMember(): void
    {
        $service = $this->service();
        $this->assertRefused(VaultGrantRefusedException::GRANTEE_NOT_IN_SCOPE, fn (): VaultGrantResult => $service->grantAs($this->owner, VaultResourceType::Item, $this->scopeItem->getId(), GranteeType::User, '2', VaultPermission::Read));
        $this->assertRefused(VaultGrantRefusedException::GRANTEE_NOT_IN_SCOPE, fn (): VaultGrantResult => $service->grantAs($this->owner, VaultResourceType::Item, $this->scopeItem->getId(), GranteeType::User, '404', VaultPermission::Read));

        $this->members['2']['org-1'] = new VaultScopeAccess(null);
        $service                     = $this->service();
        self::assertSame(VaultGrantResult::Created, $service->grantAs($this->owner, VaultResourceType::Item, $this->scopeItem->getId(), GranteeType::User, '2', VaultPermission::Read));
        // Teams are checked by the host GRANT_LIST_QUERY listener only.
        self::assertSame(VaultGrantResult::Created, $service->grantAs($this->owner, VaultResourceType::Item, $this->scopeItem->getId(), GranteeType::Team, 'org-1:ops', VaultPermission::Read));
    }

    public function testPolicyCheckCanVeto(): void
    {
        $this->dispatcher->addListener(VaultEvents::GRANT_POLICY_CHECK, static function (VaultGrantPolicyCheckEvent $event): void {
            self::assertSame(VaultResourceType::Folder, $event->getResourceType());
            self::assertSame('2', $event->getGranteeId());
            self::assertSame(GranteeType::User, $event->getGranteeType());
            self::assertSame(VaultPermission::Read, $event->getPermission());
            self::assertNull($event->getExpiresAt());
            self::assertSame('owner@example.com', $event->getActor()->getUserIdentifier());
            self::assertNotSame('', $event->getResourceId());
            self::assertFalse($event->isDenied());
            $event->deny('team_self_grant');
        });

        $this->assertRefused('team_self_grant', fn (): VaultGrantResult => $this->service()->grantAs($this->owner, VaultResourceType::Folder, $this->folder->getId(), GranteeType::User, '2', VaultPermission::Read));
    }

    public function testPolicyDenyWithoutCodeDefaultsToPolicyDenied(): void
    {
        $this->dispatcher->addListener(VaultEvents::GRANT_POLICY_CHECK, static fn (VaultGrantPolicyCheckEvent $event) => $event->deny(''));

        $this->assertRefused(VaultGrantRefusedException::POLICY_DENIED, fn (): VaultGrantResult => $this->grantAs($this->service(), VaultPermission::Read));
    }

    public function testUniqueViolationOnInsertIsRetriedAsUpdate(): void
    {
        $this->grants->raceInsert = new VaultGrant(VaultResourceType::Item, $this->item->getId(), GranteeType::User, '2', VaultPermission::Read, $this->owner);

        self::assertSame(VaultGrantResult::Updated, $this->grantAs($this->service(), VaultPermission::Write));
        self::assertSame(VaultPermission::Write, $this->onlyGrant()->getPermission());
        self::assertSame([VaultEvents::GRANT_UPDATED], $this->events);
    }

    public function testUniqueViolationWithoutAnExactRowIsARefusal(): void
    {
        $repository = new class extends InMemoryGrantRepository {
            public function findOne(VaultResourceType $resourceType, string $resourceId, GranteeType $granteeType, string $granteeId): ?VaultGrant
            {
                return null;
            }
        };
        $repository->raceInsert = new VaultGrant(VaultResourceType::Item, 'x', GranteeType::User, '9', VaultPermission::Read, $this->owner);
        $this->grants           = $repository;

        // LQ-V-P3-I1: e.g. a folded grantee_id collation; a refusal, never a 500.
        try {
            $this->grantAs($this->service(), VaultPermission::Read);
            self::fail('Expected a refusal.');
        } catch (VaultGrantRefusedException $exception) {
            self::assertSame(VaultGrantRefusedException::GRANTEE_NOT_ALLOWED, $exception->getReason());
        }
    }

    public function testUpdateOfAPurgedRowIsRetriedAsInsert(): void
    {
        $service = $this->service();
        $this->grantAs($service, VaultPermission::Read, '+1 day');
        $this->grants->raceUpdate = true;

        self::assertSame(VaultGrantResult::Created, $this->grantAs($service, VaultPermission::Read, '+2 days'));
        self::assertCount(1, $this->grants->grants);
    }

    public function testRevokeAsIsRaceSafe(): void
    {
        $service = $this->service();
        $this->grantAs($service, VaultPermission::Read);
        $grant        = $this->onlyGrant();
        $this->events = [];

        self::assertTrue($service->revokeAs($this->owner, $grant));
        self::assertFalse($service->revokeAs($this->owner, $grant));
        self::assertSame([VaultEvents::GRANT_REVOKED], $this->events);
    }

    public function testRevokeAsRechecksShare(): void
    {
        $service = $this->service();
        $this->grantAs($service, VaultPermission::Read);

        $this->assertRefused(VaultGrantRefusedException::ACTOR_CANNOT_SHARE, fn (): bool => $service->revokeAs($this->grantee, $this->onlyGrant()));
    }

    public function testDeprecatedApiEmitsEventsWithoutActor(): void
    {
        $actors = [];
        $this->dispatcher->addListener(VaultEvents::GRANT_CREATED, static function (VaultGrantChangeEvent $event) use (&$actors): void {
            $actors[] = $event->getActorId();
        });
        $service = $this->service();
        $grant   = $service->grant(VaultResourceType::Item, $this->item->getId(), GranteeType::User, '2', VaultPermission::Read, $this->owner);
        $service->revoke($grant);

        self::assertSame([null], $actors);
        self::assertSame([VaultEvents::GRANT_CREATED, VaultEvents::GRANT_REVOKED], $this->events);
        self::assertSame([], $this->grants->grants);
    }

    public function testChangeEventCarriesOwnerAndPreviousValues(): void
    {
        $this->grants->owners['item:' . $this->item->getId()] = 'user:1';
        /** @var list<VaultGrantChangeEvent> $updates */
        $updates = [];
        $this->dispatcher->addListener(VaultEvents::GRANT_UPDATED, static function (VaultGrantChangeEvent $event) use (&$updates): void {
            $updates[] = $event;
        });
        $service = $this->service();
        $this->grantAs($service, VaultPermission::Read, '+1 day');
        $this->grantAs($service, VaultPermission::Read, '+2 days');

        $event = $updates[0];
        self::assertSame('user:1', $event->getOwnerKey());
        self::assertSame(VaultPermission::Read, $event->getPreviousPermission());
        self::assertEquals($this->clock->now()->modify('+1 day'), $event->getPreviousExpiresAt());
        self::assertEquals($this->clock->now()->modify('+2 days'), $event->getExpiresAt());
        self::assertSame('1', $event->getActorId());
        self::assertSame(VaultResourceType::Item, $event->getResourceType());
        self::assertSame($this->item->getId(), $event->getResourceId());
        self::assertSame(GranteeType::User, $event->getGranteeType());
        self::assertSame('2', $event->getGranteeId());
        self::assertSame(VaultPermission::Read, $event->getPermission());
        self::assertNull($event->getReason());
        self::assertNotSame('', $event->getGrantId());
        self::assertEquals($this->clock->now(), $event->getOccurredAt());
    }

    public function testLegacyRepositoryOwnerKeyAndWrites(): void
    {
        $legacy = $this->createMock(VaultGrantRepositoryInterface::class);
        $legacy->method('findOne')->willReturn(null);
        $legacy->expects(self::exactly(2))->method('save');
        $owners = [];
        $this->dispatcher->addListener(VaultEvents::GRANT_CREATED, static function (VaultGrantChangeEvent $event) use (&$owners): void {
            $owners[] = $event->getOwnerKey();
        });
        $this->grants = new InMemoryGrantRepository();
        $service      = new VaultGrantService($legacy, null, $this->dispatcher, $this->clock, null, $this->items(), $this->folders());
        $service->grant(VaultResourceType::Item, $this->item->getId(), GranteeType::User, '2', VaultPermission::Read, $this->owner);
        $service->grant(VaultResourceType::Folder, 'gone', GranteeType::User, '2', VaultPermission::Read, $this->owner);

        self::assertSame(['user:1', null], $owners);
    }

    public function testGrantAsNeedsTheGuard(): void
    {
        $this->expectException(LogicException::class);
        (new VaultGrantService(new InMemoryGrantRepository(), itemRepository: $this->items()))->grantAs($this->owner, VaultResourceType::Item, $this->item->getId(), GranteeType::User, '2', VaultPermission::Read);
    }

    public function testGrantAsNeedsTheRepositories(): void
    {
        $this->expectException(LogicException::class);
        (new VaultGrantService(new InMemoryGrantRepository()))->grantAs($this->owner, VaultResourceType::Item, 'x', GranteeType::User, '2', VaultPermission::Read);
    }

    public function testGrantAsNeedsTheFolderRepository(): void
    {
        $this->expectException(LogicException::class);
        (new VaultGrantService(new InMemoryGrantRepository()))->grantAs($this->owner, VaultResourceType::Folder, 'x', GranteeType::User, '2', VaultPermission::Read);
    }

    public function testRefusalIsLoggedWithCodeOnly(): void
    {
        try {
            $this->grantAs($this->service(), VaultPermission::Write, '+1 minute');
        } catch (VaultGrantRefusedException $exception) {
            self::assertSame(VaultGrantRefusedException::EXPIRY_OUT_OF_RANGE, $exception->getReason());
        }

        $refusal = $this->logger->records[0];
        self::assertSame('notice', $refusal['level']);
        self::assertSame(['bundle', 'action', 'code', 'actor_id', 'resource_type', 'resource_id'], array_keys($refusal['context']));
    }

    private function grantAs(VaultGrantService $service, VaultPermission $permission, ?string $expiry = null): VaultGrantResult
    {
        return $service->grantAs(
            $this->owner,
            VaultResourceType::Item,
            $this->item->getId(),
            GranteeType::User,
            '2',
            $permission,
            $expiry !== null ? $this->clock->now()->modify($expiry) : null,
        );
    }

    private function onlyGrant(): VaultGrant
    {
        self::assertCount(1, $this->grants->grants);

        return array_values($this->grants->grants)[0];
    }

    /**
     * @param callable(): mixed $call
     */
    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Expected a refusal ' . $code);
        } catch (VaultGrantRefusedException $exception) {
            self::assertSame($code, $exception->getReason());
            self::assertStringContainsString($code, $exception->getMessage());
        }
    }

    /**
     * @param array{min_ttl?: string, max_ttl?: string, max_permission?: string} $temporary
     */
    private function service(array $temporary = [], bool $allowUnlisted = true): VaultGrantService
    {
        $resolver = new class(fn (string $id, string $scope): ?VaultScopeAccess => $this->members[$id][$scope] ?? null) implements VaultScopeAccessResolverInterface {
            public function __construct(private readonly Closure $lookup)
            {
            }

            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return ($this->lookup)($user instanceof TestUser ? $user->getId() : '', $scope);
            }
        };
        $guard   = new VaultAccessGuard($this->grants, new NullVaultTeamMembershipResolver(), $this->dispatcher, new RequestScopedScopeAccessCache($resolver), $this->clock);
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('find')->willReturnCallback(static fn (string $class, string $id): ?UserInterface => $id === '404' ? null : new TestUser($id, 'u' . $id . '@example.com'));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return new VaultGrantService(
            $this->grants,
            $this->logger,
            $this->dispatcher,
            $this->clock,
            $guard,
            $this->items(),
            $this->folders(),
            new VaultGrantListResolver($this->dispatcher, $allowUnlisted),
            new VaultUserLocator($registry, TestUser::class),
            $temporary,
        );
    }

    private function items(): VaultItemRepositoryInterface
    {
        $items = $this->createMock(VaultItemRepositoryInterface::class);
        $items->method('findById')->willReturnCallback(fn (string $id): ?VaultItem => match ($id) {
            $this->item->getId()      => $this->item,
            $this->scopeItem->getId() => $this->scopeItem,
            default                   => null,
        });

        return $items;
    }

    private function folders(): VaultFolderRepositoryInterface
    {
        $folders = $this->createMock(VaultFolderRepositoryInterface::class);
        $folders->method('findById')->willReturnCallback(fn (string $id): ?VaultFolder => $id === $this->folder->getId() ? $this->folder : null);

        return $folders;
    }
}
