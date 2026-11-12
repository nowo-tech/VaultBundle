<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultCatalogueAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFieldRevealCheckEvent;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Nowo\VaultBundle\Event\VaultGrantPolicyCheckEvent;
use Nowo\VaultBundle\Event\VaultHealthAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Event\VaultItemListResultEvent;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Event\VaultTagChangeEvent;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * 1.19.0 (REQ-ARCH-001 rules 3 and 4): every hook event gives the user as a framework-free reference and the ids of
 * its resources; folders and tags dispatch id-only change events after the vault's write.
 */
final class EventRefAccessorsTest extends TestCase
{
    #[Test]
    public function hookEventsGiveReferencesAndIds(): void
    {
        $user   = new TestUser('u-1', 'alice@example.com');
        $item   = new VaultItem(VaultItemType::Login, 'T', $user, 'c');
        $folder = new VaultFolder('F', $user);

        $access = new VaultItemAccessCheckEvent($user, $item, VaultAccessAction::View, true);
        self::assertSame($user, $access->getUserRef()->user());
        self::assertSame('u-1', $access->getUserId());
        self::assertSame($item->getId(), $access->getItemId());
        self::assertSame('user:u-1', $access->getOwnerKey());

        $folderAccess = new VaultFolderAccessCheckEvent($user, $folder, VaultAccessAction::Edit, false);
        self::assertSame('u-1', $folderAccess->getUserId());
        self::assertSame($folder->getId(), $folderAccess->getFolderId());
        self::assertSame('user:u-1', $folderAccess->getOwnerKey());

        $readOnly = new VaultItemReadOnlyEvent($user, $item);
        self::assertSame([$item->getId(), 'user:u-1', 'u-1'], [$readOnly->getItemId(), $readOnly->getOwnerKey(), $readOnly->getUserId()]);
        $reveal = new VaultFieldRevealCheckEvent($user, $item, 'password', 'reveal', 'web');
        self::assertSame([$item->getId(), 'user:u-1', 'u-1'], [$reveal->getItemId(), $reveal->getOwnerKey(), $reveal->getUserId()]);
        self::assertSame('alice@example.com', $reveal->getUserRef()->identifier);

        self::assertSame('u-1', (new VaultCatalogueAccessCheckEvent($user, 'org-1', true))->getUserId());
        self::assertSame('u-1', (new VaultHealthAccessCheckEvent($user, 'scope:org-1', true))->getUserRef()->id);
        self::assertSame('u-1', (new VaultHealthAccessCheckEvent($user, 'scope:org-1', true))->getUserId());
        self::assertSame($user, (new VaultCatalogueAccessCheckEvent($user, 'org-1', true))->getUserRef()->user());
        self::assertSame($user, (new VaultGrantPolicyCheckEvent($user, VaultResourceType::Item, 'i', GranteeType::User, 'g', VaultPermission::Read, null))->getActorRef()->user());
        self::assertSame('u-1', (new VaultGrantPolicyCheckEvent($user, VaultResourceType::Item, 'i', GranteeType::User, 'g', VaultPermission::Read, null))->getActorId());
        self::assertSame('u-1', (new VaultGrantListQueryEvent($user, VaultResourceType::Item, $item->getId(), $item))->getGrantedById());
        self::assertSame($user, (new VaultGrantListQueryEvent($user, VaultResourceType::Item, $item->getId(), $item))->getGrantedByRef()->user());

        $result = new VaultItemListResultEvent($user, [$item], 1);
        self::assertSame([$item->getId()], $result->getItemIds());
        self::assertSame('u-1', $result->getViewerId());
        self::assertSame($user, $result->getViewerRef()->user());
    }

    #[Test]
    public function theListSubjectCanBeChangedWithAReference(): void
    {
        $viewer = new TestUser('u-1');
        $found  = new TestUser('u-2', 'found@example.com');
        $event  = new VaultItemListQueryEvent($viewer, $viewer, refResolver: static fn (VaultUserRef $ref): ?TestUser => $ref->id === 'u-2' ? $found : null);
        self::assertSame('u-1', $event->getViewerId());
        self::assertSame('u-1', $event->getListSubjectId());

        $event->setListSubjectRef(VaultUserRef::ofId('u-2', 'forged', ['ROLE_ADMIN']));
        self::assertSame($found, $event->getListSubject(), 'resolved again: the reference only names the id');
        self::assertSame('u-2', $event->getListSubjectRef()->id);
        $other = new TestUser('u-3');
        $event->setListSubjectRef(new VaultUserRef('u-3', '', [], $other));
        self::assertSame($other, $event->getListSubject());

        $this->expectException(InvalidArgumentException::class);
        $event->setListSubjectRef(new VaultUserRef(null));
    }

    #[Test]
    public function foldersAndTagsDispatchIdOnlyChangeEvents(): void
    {
        $user       = new TestUser('u-1');
        $clock      = new MockClock('2026-11-12 10:00:00');
        $dispatcher = new EventDispatcher();
        $seen       = [];
        $dispatcher->addListener(VaultEvents::FOLDER_CHANGED, static function (VaultFolderChangeEvent $event) use (&$seen): void {
            $seen[] = ['folder', $event->getKind(), $event->getActorId(), $event->getOwnerKey(), $event->getFolderId() !== '', $event->getOccurredAt()->format('H:i')];
        });
        $dispatcher->addListener(VaultEvents::TAG_CHANGED, static function (VaultTagChangeEvent $event) use (&$seen): void {
            $seen[] = ['tag', $event->getKind(), $event->getActorId(), $event->getOwnerKey(), $event->getTagId() !== '', $event->getOccurredAt()->format('H:i')];
        });

        $folders = new VaultFolderService($this->createMock(VaultFolderRepositoryInterface::class), null, $dispatcher, $clock);
        $folder  = $folders->create('F', $user);
        $folders->rename($folder, 'G');
        $trash = new VaultTrashService($this->createMock(VaultItemRepositoryInterface::class), $this->createMock(VaultFolderRepositoryInterface::class), eventDispatcher: $dispatcher, clock: $clock);
        $trash->moveFolderToTrash($folder);
        $trash->restoreFolder($folder);
        $trash->purgeFolder($folder);

        $tagRepository = $this->createMock(VaultTagRepositoryInterface::class);
        $tags          = new VaultTagService($tagRepository, $this->createMock(VaultItemRepositoryInterface::class), $dispatcher, $clock);
        $tag           = $tags->findOrCreate($user, 'work');
        $tagRepository->method('findById')->willReturn($tag);
        $tags->deleteForCreator($user, $tag->getId());

        self::assertSame([
            ['folder', 'created', 'u-1', 'user:u-1', true, '10:00'],
            ['folder', 'renamed', null, 'user:u-1', true, '10:00'],
            ['folder', 'trashed', null, 'user:u-1', true, '10:00'],
            ['folder', 'restored', null, 'user:u-1', true, '10:00'],
            ['folder', 'purged', null, 'user:u-1', true, '10:00'],
            ['tag', 'created', 'u-1', 'user:u-1', true, '10:00'],
            ['tag', 'deleted', 'u-1', 'user:u-1', true, '10:00'],
        ], $seen);
        self::assertInstanceOf(DateTimeImmutable::class, (new VaultFolderChangeEvent(null, 'f', 'scope:x', 'created', new DateTimeImmutable()))->getOccurredAt());
    }
}
