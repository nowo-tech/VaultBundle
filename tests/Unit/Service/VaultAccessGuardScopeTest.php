<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Closure;
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
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Tests\Stub\InMemoryGrantRepository;
use Nowo\VaultBundle\Tests\Stub\TestTeamMembershipResolver;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * 1.16.0 effective permission: scope membership gate, baseline, expiry, same-owner folder rule, permission map.
 */
final class VaultAccessGuardScopeTest extends TestCase
{
    private MockClock $clock;
    private InMemoryGrantRepository $grants;
    private EventDispatcher $dispatcher;
    private int $resolves = 0;

    /** @var array<string, array<string, VaultScopeAccess>> */
    private array $members = [];

    protected function setUp(): void
    {
        $this->clock      = new MockClock('2026-11-05 12:00:00');
        $this->grants     = new InMemoryGrantRepository($this->clock->now());
        $this->dispatcher = new EventDispatcher();
    }

    public function testNonMemberIsDeniedEvenWithAGrantAndAListener(): void
    {
        $item = VaultItem::forScope(VaultItemType::Login, 'Stripe', 'org-1', null, 'c');
        $this->grants->save(new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '2', VaultPermission::Admin, null));
        $this->dispatcher->addListener(VaultEvents::ITEM_ACCESS_CHECK, static fn (VaultItemAccessCheckEvent $event) => $event->grant());
        $guard = $this->guard();
        $user  = new TestUser('2');

        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::View));
        self::assertSame([$item->getId() => null], $guard->resolvePermissionMap($user, [$item]));

        $this->members['2']['org-1'] = new VaultScopeAccess(null);
        self::assertTrue($this->guard()->canAccessItem($user, $item, VaultAccessAction::Share));
    }

    public function testBaselineAndGrantsCombine(): void
    {
        $item                        = VaultItem::forScope(VaultItemType::Login, 'Stripe', 'org-1', null, 'c');
        $this->members['2']['org-1'] = new VaultScopeAccess(VaultPermission::Read);
        $user                        = new TestUser('2');

        $guard = $this->guard();
        self::assertTrue($guard->canAccessItem($user, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::Edit));

        $this->grants->save(new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '2', VaultPermission::Write, null));
        self::assertTrue($guard->canAccessItem($user, $item, VaultAccessAction::Edit));
        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::Delete));
        self::assertSame(VaultPermission::Write, $guard->resolvePermissionMap($user, [$item])[$item->getId()]);
    }

    public function testMemberWithoutBaselineAndWithoutGrantSeesNothing(): void
    {
        $item                        = VaultItem::forScope(VaultItemType::Login, 'Stripe', 'org-1', null, 'c');
        $this->members['2']['org-1'] = new VaultScopeAccess(null);

        self::assertFalse($this->guard()->canAccessItem(new TestUser('2'), $item, VaultAccessAction::View));
    }

    public function testExpiredGrantIsIgnoredWithoutPurge(): void
    {
        $owner = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Mail', $owner, 'c');
        $this->grants->save(new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '2', VaultPermission::Read, $owner, $this->clock->now()->modify('+1 hour')));
        $guard = $this->guard();
        $user  = new TestUser('2');

        self::assertTrue($guard->canAccessItem($user, $item, VaultAccessAction::View));
        $this->clock->modify('+1 hour');
        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::View));
        self::assertSame([$item->getId() => null], $guard->resolvePermissionMap($user, [$item]));
        self::assertCount(1, $this->grants->grants, 'Enforcement does not depend on the purge.');
    }

    public function testExpiredGrantIsIgnoredWithALegacyRepository(): void
    {
        $owner = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Mail', $owner, 'c');
        $grant = new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '2', VaultPermission::Read, $owner, $this->clock->now()->modify('-1 second'));
        $repo  = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findByResource')->willReturn([$grant]);
        $repo->method('findByGrantee')->willReturn([$grant]);
        $guard = new VaultAccessGuard($repo, new TestTeamMembershipResolver([]), $this->dispatcher, null, $this->clock);

        self::assertFalse($guard->canAccessItem(new TestUser('2'), $item, VaultAccessAction::View));
    }

    public function testFolderGrantsApplyOnlyWithTheSameOwner(): void
    {
        $scopeFolder   = VaultFolder::forScope('Ops', 'org-1', null);
        $otherFolder   = VaultFolder::forScope('Other', 'org-2', null);
        $inFolder      = VaultItem::forScope(VaultItemType::Login, 'A', 'org-1', null, 'c', $scopeFolder);
        $foreignFolder = VaultItem::forScope(VaultItemType::Login, 'B', 'org-1', null, 'c');
        $foreignFolder->setFolder($otherFolder);
        $this->members['2'] = ['org-1' => new VaultScopeAccess(null), 'org-2' => new VaultScopeAccess(null)];
        $this->grants->save(new VaultGrant(VaultResourceType::Folder, $scopeFolder->getId(), GranteeType::Team, 'ops', VaultPermission::Admin, null));
        $this->grants->save(new VaultGrant(VaultResourceType::Folder, $otherFolder->getId(), GranteeType::User, '2', VaultPermission::Admin, null));
        $guard = $this->guard(['2' => ['ops']]);
        $user  = new TestUser('2');

        self::assertTrue($guard->canAccessItem($user, $inFolder, VaultAccessAction::Share));
        self::assertFalse($guard->canAccessItem($user, $foreignFolder, VaultAccessAction::View), 'SEC-004 M1, scope variant');
        self::assertSame([$inFolder->getId() => VaultPermission::Admin, $foreignFolder->getId() => null], $guard->resolvePermissionMap($user, [$inFolder, $foreignFolder]));
    }

    public function testScopeFolderAccess(): void
    {
        $folder = VaultFolder::forScope('Ops', 'org-1', null);
        $user   = new TestUser('2');

        self::assertFalse($this->guard()->canAccessFolder($user, $folder, VaultAccessAction::View));

        $this->members['2']['org-1'] = new VaultScopeAccess(VaultPermission::Write);
        $guard                       = $this->guard();
        self::assertTrue($guard->canAccessFolder($user, $folder, VaultAccessAction::Edit));
        self::assertFalse($guard->canAccessFolder($user, $folder, VaultAccessAction::Share));

        $this->grants->save(new VaultGrant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, '2', VaultPermission::Admin, null));
        self::assertTrue($guard->canAccessFolder($user, $folder, VaultAccessAction::Share));
    }

    public function testPersonalFolderGrantWithALegacyRepository(): void
    {
        $owner  = new TestUser('1');
        $folder = new VaultFolder('Mine', $owner);
        $grant  = new VaultGrant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, '2', VaultPermission::Read, $owner);
        $repo   = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findByResource')->willReturn([$grant]);
        $guard = new VaultAccessGuard($repo, new TestTeamMembershipResolver([]), $this->dispatcher);

        self::assertTrue($guard->canAccessFolder(new TestUser('2'), $folder, VaultAccessAction::View));
        self::assertTrue($guard->canAccessFolder($owner, $folder, VaultAccessAction::Purge));
        self::assertFalse($guard->canAccessFolder(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'anon';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }
        }, $folder, VaultAccessAction::View));
    }

    public function testPermissionMapUsesListenersAndConstantQueries(): void
    {
        $owner = new TestUser('1');
        $items = [];
        for ($i = 0; $i < 5; ++$i) {
            $items[] = new VaultItem(VaultItemType::Login, 'I' . $i, $owner, 'c');
        }
        $this->grants->save(new VaultGrant(VaultResourceType::Item, $items[0]->getId(), GranteeType::User, '2', VaultPermission::Read, $owner));
        $vetoed  = $items[0]->getId();
        $granted = $items[1]->getId();
        $this->dispatcher->addListener(VaultEvents::ITEM_ACCESS_CHECK, static function (VaultItemAccessCheckEvent $event) use ($vetoed, $granted): void {
            if ($event->getItem()->getId() === $vetoed) {
                $event->deny();
            }
            if ($event->getItem()->getId() === $granted) {
                $event->grant();
            }
        });

        $map = $this->guard()->resolvePermissionMap(new TestUser('2'), $items);
        self::assertNull($map[$vetoed]);
        self::assertSame(VaultPermission::Read, $map[$granted]);
        self::assertNull($map[$items[2]->getId()]);
        self::assertSame(VaultPermission::Admin, $this->guard()->resolvePermissionMap($owner, $items)[$items[3]->getId()]);
    }

    public function testScopeMembershipIsMemoizedPerRequestAndReset(): void
    {
        $this->members['2']['org-1'] = new VaultScopeAccess(VaultPermission::Read);
        $cache                       = new RequestScopedScopeAccessCache($this->resolver());
        $user                        = new TestUser('2');

        self::assertInstanceOf(VaultScopeAccess::class, $cache->resolve($user, 'org-1'));
        self::assertInstanceOf(VaultScopeAccess::class, $cache->resolve($user, 'org-1'));
        self::assertNull($cache->resolve($user, 'Org-1'), 'A malformed key never reaches the host resolver.');
        self::assertSame(1, $this->resolves);

        unset($this->members['2']);
        self::assertInstanceOf(VaultScopeAccess::class, $cache->resolve($user, 'org-1'));
        $cache->reset();
        self::assertNull($cache->resolve($user, 'org-1'), 'Leaving the scope takes effect on the next request.');
        self::assertSame(2, $this->resolves);
        self::assertNull((new NullVaultScopeAccessResolver())->resolve($user, 'org-1'));
    }

    public function testWithoutScopeCacheNobodyIsAMember(): void
    {
        $item  = VaultItem::forScope(VaultItemType::Login, 'Stripe', 'org-1', null, 'c');
        $guard = new VaultAccessGuard($this->grants, new TestTeamMembershipResolver([]), $this->dispatcher);

        self::assertNull($guard->resolveScopeAccess(new TestUser('2'), 'org-1'));
        self::assertFalse($guard->canAccessItem(new TestUser('2'), $item, VaultAccessAction::View));
        self::assertSame([], $guard->resolvePermissionMap(new TestUser('2'), []));
    }

    /**
     * @param array<string, list<string>> $teams
     */
    private function guard(array $teams = []): VaultAccessGuard
    {
        return new VaultAccessGuard($this->grants, new TestTeamMembershipResolver($teams), $this->dispatcher, new RequestScopedScopeAccessCache($this->resolver()), $this->clock);
    }

    private function resolver(): VaultScopeAccessResolverInterface
    {
        return new class(function (string $id, string $scope): ?VaultScopeAccess {
            ++$this->resolves;

            return $this->members[$id][$scope] ?? null;
        }) implements VaultScopeAccessResolverInterface {

            public function __construct(private readonly Closure $lookup)
            {
            }

            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return ($this->lookup)($user instanceof TestUser ? $user->getId() : '', $scope);
            }
        };
    }
}
