<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class VaultAccessGuardTest extends TestCase
{
    public function testReadOnlyEventBlocksEditButAllowsView(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_READ_ONLY_RESOLVE, static function (VaultItemReadOnlyEvent $event): void {
            $event->markReadOnly();
        });

        $guard = new VaultAccessGuard($this->createMock(VaultGrantRepositoryInterface::class), new NullVaultTeamMembershipResolver(), $dispatcher);
        $user  = new TestUser('42');
        $item  = new VaultItem(VaultItemType::Login, 'Demo', $user, 'cipher');

        self::assertTrue($guard->canAccessItem($user, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::Edit));
        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::Delete));
        self::assertTrue($guard->isItemReadOnly($user, $item));
    }

    public function testAccessCheckEventCanMarkReadOnly(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_ACCESS_CHECK, static function (VaultItemAccessCheckEvent $event): void {
            if ($event->getAction() === VaultAccessAction::Edit) {
                $event->markReadOnly();
            }
        });

        $guard = new VaultAccessGuard($this->createMock(VaultGrantRepositoryInterface::class), new NullVaultTeamMembershipResolver(), $dispatcher);
        $user  = new TestUser('7');
        $item  = new VaultItem(VaultItemType::SecureNote, 'Note', $user, 'cipher');

        self::assertFalse($guard->canAccessItem($user, $item, VaultAccessAction::Edit));
        self::assertTrue($guard->canAccessItem($user, $item, VaultAccessAction::View));
    }

    public function testResolveReadOnlyMap(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_READ_ONLY_RESOLVE, static function (VaultItemReadOnlyEvent $event): void {
            if ($event->getItem()->getTitle() === 'locked') {
                $event->markReadOnly();
            }
        });

        $guard  = new VaultAccessGuard($this->createMock(VaultGrantRepositoryInterface::class), new NullVaultTeamMembershipResolver(), $dispatcher);
        $user   = new TestUser('1');
        $open   = new VaultItem(VaultItemType::Login, 'open', $user, 'c1');
        $locked = new VaultItem(VaultItemType::Login, 'locked', $user, 'c2');

        $map = $guard->resolveReadOnlyMap($user, [$open, $locked]);

        self::assertFalse($map[$open->getId()]);
        self::assertTrue($map[$locked->getId()]);
    }
}
