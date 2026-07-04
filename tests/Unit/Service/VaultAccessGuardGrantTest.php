<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Tests\Stub\TestTeamMembershipResolver;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class VaultAccessGuardGrantTest extends TestCase
{
    public function testCreatorHasFullAccess(): void
    {
        $guard = $this->guard([]);
        $user  = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Mine', $user, 'c');

        self::assertTrue($guard->canAccessItem($user, $item, VaultAccessAction::Share));
    }

    public function testUserGrantAllowsWriteButNotAdmin(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('2');
        $item   = new VaultItem(VaultItemType::Login, 'Shared', $owner, 'c');
        $grant  = new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '2', VaultPermission::Write, $owner);

        $guard = $this->guard([$grant]);

        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::View));
        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::Edit));
        self::assertFalse($guard->canAccessItem($viewer, $item, VaultAccessAction::Delete));
    }

    public function testTeamGrantViaMembershipResolver(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('9');
        $item   = new VaultItem(VaultItemType::Login, 'Team item', $owner, 'c');
        $grant  = new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::Team, 'team-a', VaultPermission::Read, $owner);

        $repo = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findByResource')->willReturn([$grant]);
        $repo->method('findByGrantee')->willReturn([]);

        $guard = new VaultAccessGuard(
            $repo,
            new TestTeamMembershipResolver(['9' => ['team-a']]),
            new EventDispatcher(),
        );

        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($viewer, $item, VaultAccessAction::Edit));
    }

    public function testFolderGrantInheritsToItem(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('3');
        $folder = new VaultFolder('Shared folder', $owner);
        $item   = new VaultItem(VaultItemType::Login, 'In folder', $owner, 'c', $folder);
        $grant  = new VaultGrant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, '3', VaultPermission::Admin, $owner);

        $guard = $this->guard([$grant]);

        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::Share));
    }

    public function testFolderAccessForNonOwnerWithGrant(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('4');
        $folder = new VaultFolder('Folder', $owner);
        $grant  = new VaultGrant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, '4', VaultPermission::Read, $owner);

        $guard = $this->guard([$grant]);

        self::assertTrue($guard->canAccessFolder($viewer, $folder, VaultAccessAction::View));
        self::assertFalse($guard->canAccessFolder($viewer, $folder, VaultAccessAction::Edit));
    }

    public function testAccessCheckEventCanDeny(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_ACCESS_CHECK, static function (VaultItemAccessCheckEvent $event): void {
            $event->deny();
        });

        $user  = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Blocked', $user, 'c');
        $guard = new VaultAccessGuard($this->createMock(VaultGrantRepositoryInterface::class), new NullVaultTeamMembershipResolver(), $dispatcher);

        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::View));
    }

    public function testFolderAccessCheckEventCanDeny(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::FOLDER_ACCESS_CHECK, static function (VaultFolderAccessCheckEvent $event): void {
            $event->deny();
        });

        $user   = new TestUser('1');
        $folder = new VaultFolder('Folder', $user);
        $guard  = new VaultAccessGuard($this->createMock(VaultGrantRepositoryInterface::class), new NullVaultTeamMembershipResolver(), $dispatcher);

        self::assertFalse($guard->canAccessFolder($user, $folder, VaultAccessAction::View));
    }

    public function testReadOnlyCache(): void
    {
        $guard = $this->guard([]);
        $user  = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Item', $user, 'c');

        self::assertFalse($guard->isItemReadOnly($user, $item));
        self::assertFalse($guard->isItemReadOnly($user, $item));
    }

    public function testAdminGrantAllowsDestructiveActions(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('2');
        $item   = new VaultItem(VaultItemType::Login, 'Shared', $owner, 'c');
        $grant  = new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '2', VaultPermission::Admin, $owner);

        $guard = $this->guard([$grant]);

        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::Delete));
        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::Restore));
        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::Purge));
    }

    public function testGrantViaFindByGranteePath(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('5');
        $item   = new VaultItem(VaultItemType::Login, 'Alt path', $owner, 'c');
        $grant  = new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, '5', VaultPermission::Read, $owner);

        $repo = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findByResource')->willReturn([]);
        $repo->method('findByGrantee')->willReturn([$grant]);

        $guard = new VaultAccessGuard($repo, new NullVaultTeamMembershipResolver(), new EventDispatcher());

        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::View));
    }

    public function testNonOwnerWithoutUserIdIsDenied(): void
    {
        $owner  = new TestUser('1');
        $viewer = new class implements \Symfony\Component\Security\Core\User\UserInterface {
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
        };
        $item  = new VaultItem(VaultItemType::Login, 'Private', $owner, 'c');
        $guard = $this->guard([]);

        self::assertFalse($guard->canAccessItem($viewer, $item, VaultAccessAction::View));
    }

    public function testTeamFolderGrantViaMembership(): void
    {
        $owner  = new TestUser('1');
        $viewer = new TestUser('9');
        $folder = new VaultFolder('Team folder', $owner);
        $item   = new VaultItem(VaultItemType::Login, 'In team folder', $owner, 'c', $folder);
        $grant  = new VaultGrant(VaultResourceType::Folder, $folder->getId(), GranteeType::Team, 'team-b', VaultPermission::Write, $owner);

        $repo = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findByResource')->willReturnCallback(
            static fn (VaultResourceType $type, string $id): array => $type === VaultResourceType::Folder && $id === $folder->getId() ? [$grant] : [],
        );
        $repo->method('findByGrantee')->willReturn([]);

        $guard = new VaultAccessGuard($repo, new TestTeamMembershipResolver(['9' => ['team-b']]), new EventDispatcher());

        self::assertTrue($guard->canAccessItem($viewer, $item, VaultAccessAction::Edit));
    }

    public function testIsItemReadOnlyReturnsFalseForUserWithoutId(): void
    {
        $owner = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Item', $owner, 'c');
        $user  = new class implements \Symfony\Component\Security\Core\User\UserInterface {
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
        };

        $guard = $this->guard([]);
        self::assertFalse($guard->isItemReadOnly($user, $item));
    }

    /**
     * @param list<VaultGrant> $grants
     */
    private function guard(array $grants): VaultAccessGuard
    {
        $repo = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findByResource')->willReturnCallback(
            static fn (VaultResourceType $type, string $id): array => array_values(array_filter(
                $grants,
                static fn (VaultGrant $g): bool => $g->getResourceType() === $type && $g->getResourceId() === $id,
            )),
        );
        $repo->method('findByGrantee')->willReturnCallback(
            static fn (GranteeType $type, string $id): array => array_values(array_filter(
                $grants,
                static fn (VaultGrant $g): bool => $g->getGranteeType() === $type && $g->getGranteeId() === $id,
            )),
        );

        return new VaultAccessGuard($repo, new NullVaultTeamMembershipResolver(), new EventDispatcher());
    }
}
