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
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Event\VaultItemListResultEvent;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultItemLister;
use Nowo\VaultBundle\Service\VaultItemUpdater;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class VaultServiceTest extends TestCase
{
    public function testItemCreatorEncryptsAndPersists(): void
    {
        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->expects(self::once())->method('save');

        $crypto = $this->createMock(VaultPayloadCryptographerInterface::class);
        $crypto->method('encrypt')->with(['password' => 'secret'])->willReturn('encrypted');

        $user = new TestUser('1');
        $item = (new VaultItemCreator($repo, $crypto))->create(
            VaultItemType::Login,
            'GitHub',
            $user,
            ['password' => 'secret'],
        );

        self::assertSame('GitHub', $item->getTitle());
        self::assertSame('encrypted', $item->getCiphertext());
    }

    public function testItemUpdaterEncryptsAndPersists(): void
    {
        $user = new TestUser('1');
        $item = new VaultItem(VaultItemType::Login, 'Old', $user, 'old-cipher');
        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with($item);

        $crypto = $this->createMock(VaultPayloadCryptographerInterface::class);
        $crypto->method('encrypt')->willReturn('new-cipher');

        $folder  = new VaultFolder('Work', $user);
        $updated = (new VaultItemUpdater($repo, $crypto))->update($item, 'New', ['password' => 'x'], $folder);

        self::assertSame('New', $updated->getTitle());
        self::assertSame($folder, $updated->getFolder());
    }

    public function testFolderServiceCrud(): void
    {
        $user = new TestUser('2');
        $repo = $this->createMock(VaultFolderRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('save');
        $repo->method('findByCreator')->willReturn([]);
        $repo->method('findById')->willReturn(null);

        $service = new VaultFolderService($repo);
        $folder  = $service->create('Docs', $user);
        self::assertSame('Docs', $folder->getName());
        $service->rename($folder, 'Documents');
        self::assertSame('Documents', $folder->getName());
        self::assertSame([], $service->listForCreator($user));
        self::assertNull($service->find('missing'));
    }

    public function testTrashServiceOperations(): void
    {
        $user   = new TestUser('3');
        $item   = new VaultItem(VaultItemType::Login, 'Trash me', $user, 'c');
        $folder = new VaultFolder('Trash folder', $user);

        $itemRepo = $this->createMock(VaultItemRepositoryInterface::class);
        $itemRepo->expects(self::exactly(2))->method('save');
        $itemRepo->expects(self::once())->method('remove')->with($item);
        $itemRepo->expects(self::once())->method('detachItemsFromFolder')->with($folder)->willReturn(2);

        $folderRepo = $this->createMock(VaultFolderRepositoryInterface::class);
        $folderRepo->expects(self::exactly(2))->method('save');
        $folderRepo->expects(self::once())->method('remove')->with($folder);

        $service = new VaultTrashService($itemRepo, $folderRepo);
        $service->moveItemToTrash($item);
        self::assertTrue($item->isDeleted());
        $service->restoreItem($item);
        self::assertFalse($item->isDeleted());
        $service->purgeItem($item);

        self::assertSame(2, $service->moveFolderToTrash($folder));
        self::assertTrue($folder->isDeleted());
        $service->restoreFolder($folder);
        self::assertFalse($folder->isDeleted());
        $service->purgeFolder($folder);
    }

    public function testGrantServiceCreatesUpdatesAndRevokes(): void
    {
        $owner    = new TestUser('1');
        $existing = new VaultGrant(VaultResourceType::Item, 'res-1', GranteeType::User, '2', VaultPermission::Read, $owner);

        $repo = $this->createMock(VaultGrantRepositoryInterface::class);
        $repo->method('findOne')->willReturnOnConsecutiveCalls(null, $existing);
        $repo->expects(self::exactly(2))->method('save');
        $repo->expects(self::once())->method('remove')->with($existing);
        $repo->method('findByResource')->willReturn([$existing]);

        $service = new VaultGrantService($repo);
        $created = $service->grant(VaultResourceType::Item, 'res-1', GranteeType::User, '2', VaultPermission::Read, $owner);
        self::assertSame(VaultPermission::Read, $created->getPermission());

        $updated = $service->grant(VaultResourceType::Item, 'res-1', GranteeType::User, '2', VaultPermission::Write, $owner);
        self::assertSame(VaultPermission::Write, $updated->getPermission());

        self::assertSame([$existing], $service->listForResource(VaultResourceType::Item, 'res-1'));
        $service->revoke($existing);
    }

    public function testItemListerBranches(): void
    {
        $user = new TestUser('5');
        $item = new VaultItem(VaultItemType::Login, 'One', $user, 'c');
        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->method('findByCreator')->willReturn([$item]);
        $repo->method('findByCreatorAndFolder')->willReturn([$item]);
        $repo->method('findDeletedByCreator')->willReturn([$item]);
        $repo->method('searchByTitleOrTag')->willReturn([$item]);
        $repo->method('findByCreatorAndTag')->willReturn([$item]);

        $grantRepo = $this->createMock(VaultGrantRepositoryInterface::class);
        $grantRepo->method('findGrantedResourceIds')->willReturn([]);
        $shared = new VaultSharedItemResolver(
            $grantRepo,
            $this->createMock(VaultItemRepositoryInterface::class),
            new NullVaultTeamMembershipResolver(),
        );

        $dispatcher = new EventDispatcher();
        $lister     = new VaultItemLister($repo, $shared, $dispatcher);

        self::assertSame(1, $lister->list($user)['total']);
        self::assertSame(1, $lister->list($user, 'folder-id')['total']);
        self::assertSame(1, $lister->list($user, trashOnly: true)['total']);
        self::assertSame(1, $lister->list($user, searchQuery: 'one')['total']);
        self::assertSame(1, $lister->list($user, tagId: 'tag-id')['total']);

        $sharedGrantRepo = $this->createMock(VaultGrantRepositoryInterface::class);
        $sharedGrantRepo->method('findGrantedResourceIds')->willReturn([$item->getId()]);
        $sharedItemRepo = $this->createMock(VaultItemRepositoryInterface::class);
        $sharedItemRepo->method('findByIdsForViewer')->willReturn([$item]);
        $sharedLister = new VaultItemLister(
            $repo,
            new VaultSharedItemResolver($sharedGrantRepo, $sharedItemRepo, new NullVaultTeamMembershipResolver()),
            $dispatcher,
        );
        self::assertSame(1, $sharedLister->list($user, sharedOnly: true)['total']);
    }

    public function testItemListerRespectsQueryAndResultEvents(): void
    {
        $viewer  = new TestUser('6');
        $subject = new TestUser('7');
        $item    = new VaultItem(VaultItemType::Login, 'Override', $subject, 'c');

        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->expects(self::never())->method('findByCreator');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_LIST_QUERY, static function (VaultItemListQueryEvent $event) use ($item): void {
            $event->overrideResult([$item], 99);
        });
        $dispatcher->addListener(VaultEvents::ITEM_LIST_RESULT, static function (VaultItemListResultEvent $event): void {
            $event->setTotal(100);
        });

        $lister = new VaultItemLister($repo, new VaultSharedItemResolver(
            $this->createMock(VaultGrantRepositoryInterface::class),
            $this->createMock(VaultItemRepositoryInterface::class),
            new NullVaultTeamMembershipResolver(),
        ), $dispatcher);

        $result = $lister->list($viewer);
        self::assertSame(100, $result['total']);
        self::assertSame([$item], $result['items']);
    }
}
