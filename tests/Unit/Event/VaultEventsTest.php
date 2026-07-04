<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Event;

use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Event\VaultItemListResultEvent;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class VaultEventsTest extends TestCase
{
    public function testVaultAccessActionIsViewOnly(): void
    {
        self::assertTrue(VaultAccessAction::View->isViewOnly());
        self::assertFalse(VaultAccessAction::Edit->isViewOnly());
    }

    public function testItemAccessCheckEventMutators(): void
    {
        $user  = new TestUser('1');
        $item  = new VaultItem(VaultItemType::Login, 'Demo', $user, 'cipher');
        $event = new VaultItemAccessCheckEvent($user, $item, VaultAccessAction::Edit, false);

        self::assertFalse($event->isGranted());
        $event->grant();
        self::assertTrue($event->isGranted());
        $event->deny();
        self::assertFalse($event->isGranted());
        $event->markReadOnly();
        self::assertTrue($event->isReadOnly());
    }

    public function testFolderAccessCheckEventMutators(): void
    {
        $user   = new TestUser('2');
        $folder = new VaultFolder('Docs', $user);
        $event  = new VaultFolderAccessCheckEvent($user, $folder, VaultAccessAction::Share, true);

        self::assertTrue($event->isGranted());
        $event->deny();
        self::assertFalse($event->isGranted());
        $event->grant();
        self::assertTrue($event->isGranted());
    }

    public function testListQueryEventOverrideAndSubject(): void
    {
        $viewer  = new TestUser('3');
        $subject = new TestUser('4');
        $item    = new VaultItem(VaultItemType::SecureNote, 'Note', $subject, 'c');
        $event   = new VaultItemListQueryEvent($viewer, $subject, 'folder-1', trashOnly: true);

        self::assertSame($viewer, $event->getViewer());
        self::assertSame('folder-1', $event->getFolderId());
        self::assertTrue($event->isTrashOnly());
        self::assertFalse($event->hasOverride());

        $event->setListSubject(new TestUser('5'));
        self::assertSame('5', $event->getListSubject()->getId());
        $event->overrideResult([$item], 1);
        self::assertTrue($event->hasOverride());
        self::assertSame([$item], $event->getOverrideItems());
        self::assertSame(1, $event->getOverrideTotal());
    }

    public function testListResultEventMutators(): void
    {
        $user  = new TestUser('6');
        $item  = new VaultItem(VaultItemType::CreditCard, 'Card', $user, 'c');
        $event = new VaultItemListResultEvent($user, [$item], 1);

        self::assertSame([$item], $event->getItems());
        $event->setItems([]);
        $event->setTotal(0);
        self::assertSame([], $event->getItems());
        self::assertSame(0, $event->getTotal());
    }

    public function testReadOnlyEvent(): void
    {
        $user  = new TestUser('7');
        $item  = new VaultItem(VaultItemType::Contact, 'Contact', $user, 'c');
        $event = new VaultItemReadOnlyEvent($user, $item);

        self::assertSame($user, $event->getUser());
        self::assertSame($item, $event->getItem());
        self::assertFalse($event->isReadOnly());
        $event->markReadOnly();
        self::assertTrue($event->isReadOnly());
        $event->clearReadOnly();
        self::assertFalse($event->isReadOnly());
    }

    public function testListQueryEventSharedOnlyFlag(): void
    {
        $event = new VaultItemListQueryEvent(new TestUser('1'), new TestUser('1'), sharedOnly: true);
        self::assertTrue($event->isSharedOnly());
    }

    public function testItemAccessCheckEventGetters(): void
    {
        $user  = new TestUser('8');
        $item  = new VaultItem(VaultItemType::Login, 'X', $user, 'c');
        $event = new VaultItemAccessCheckEvent($user, $item, VaultAccessAction::Delete, true);

        self::assertSame($user, $event->getUser());
        self::assertSame($item, $event->getItem());
        self::assertSame(VaultAccessAction::Delete, $event->getAction());
        self::assertTrue($event->isGranted());
    }

    public function testFolderAccessCheckEventGetters(): void
    {
        $user   = new TestUser('9');
        $folder = new VaultFolder('F', $user);
        $event  = new VaultFolderAccessCheckEvent($user, $folder, VaultAccessAction::Purge, false);

        self::assertSame($user, $event->getUser());
        self::assertSame($folder, $event->getFolder());
        self::assertSame(VaultAccessAction::Purge, $event->getAction());
        self::assertFalse($event->isGranted());
    }

    public function testListResultEventViewerGetter(): void
    {
        $user  = new TestUser('10');
        $event = new VaultItemListResultEvent($user, [], 0);
        self::assertSame($user, $event->getViewer());
    }

    public function testGrantListQueryEvent(): void
    {
        $user   = new TestUser('11');
        $folder = new VaultFolder('Shared', $user);
        $event  = new VaultGrantListQueryEvent($user, VaultResourceType::Folder, $folder->getId(), $folder);

        self::assertFalse($event->hasGrantees());
        self::assertTrue($event->isGranteeAllowed(GranteeType::User, 'any'));

        $event->addGrantee(new VaultGranteeChoice(GranteeType::User, 'u1', 'Alice'));
        $event->addGrantee(new VaultGranteeChoice(GranteeType::Team, 'g1', 'Ops group'));

        self::assertTrue($event->hasGrantees());
        self::assertTrue($event->isGranteeAllowed(GranteeType::User, 'u1'));
        self::assertFalse($event->isGranteeAllowed(GranteeType::User, 'u2'));
        self::assertSame('Ops group', $event->getLabelFor(GranteeType::Team, 'g1'));
        self::assertSame(['user:u1' => 'Alice', 'team:g1' => 'Ops group'], $event->getLabelMap());

        $event->removeGrantee(GranteeType::User, 'u1');
        self::assertCount(1, $event->getGrantees());
        self::assertCount(1, $event->getGrantees(GranteeType::Team));
    }
}
