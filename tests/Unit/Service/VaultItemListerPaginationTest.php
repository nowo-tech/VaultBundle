<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemPaginatorInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Service\VaultItemLister;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;
use function range;

/**
 * Page / perPage handling of {@see VaultItemLister} (REQ-PERF-001).
 */
final class VaultItemListerPaginationTest extends TestCase
{
    private TestUser $user;

    protected function setUp(): void
    {
        $this->user = new TestUser('42');
    }

    public function testPaginatorRepositoryReceivesOffsetLimitAndFilters(): void
    {
        $page = $this->items(2);
        /** @var MockObject&VaultItemPaginatorInterface&VaultItemRepositoryInterface $repo */
        $repo = $this->createMockForIntersectionOfInterfaces([VaultItemRepositoryInterface::class, VaultItemPaginatorInterface::class]);
        $repo->expects(self::never())->method('findByCreator');
        $calls = [];
        $repo->method('paginateByCreator')->willReturnCallback(
            static function (object $creator, ?string $folderId, ?string $tagId, bool $trashOnly, int $offset, int $limit) use (&$calls, $page): array {
                $calls[] = [$folderId, $tagId, $trashOnly, $offset, $limit];

                return ['items' => $page, 'total' => 23];
            },
        );

        $lister = $this->lister($repo);

        $result = $lister->list($this->user, 'folder-1', tagId: 'tag-1', page: 3, perPage: 10);
        self::assertSame($page, $result['items']);
        self::assertSame(23, $result['total']);

        // Trash ignores folder and tag; an empty tag is no filter; page below 1 is page 1.
        $lister->list($this->user, 'folder-1', trashOnly: true, tagId: 'tag-1', page: 2, perPage: 5);
        $lister->list($this->user, null, tagId: '', page: 0, perPage: 5);

        self::assertSame([
            ['folder-1', 'tag-1', false, 20, 10],
            [null, null, true, 5, 5],
            [null, null, false, 0, 5],
        ], $calls);
    }

    public function testSearchIsNotPaginatedEvenWithPaginatorRepository(): void
    {
        $found = $this->items(3);
        /** @var MockObject&VaultItemPaginatorInterface&VaultItemRepositoryInterface $repo */
        $repo = $this->createMockForIntersectionOfInterfaces([VaultItemRepositoryInterface::class, VaultItemPaginatorInterface::class]);
        $repo->expects(self::never())->method('paginateByCreator');
        $repo->expects(self::once())->method('searchByTitleOrTag')->with($this->user, 'mail')->willReturn($found);

        $result = $this->lister($repo)->list($this->user, searchQuery: 'mail');

        self::assertSame($found, $result['items']);
        self::assertSame(3, $result['total']);
    }

    public function testRepositoryWithoutPaginatorIsSlicedInPhp(): void
    {
        $all  = $this->items(7);
        $repo = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->method('findByCreator')->willReturn($all);
        $repo->method('findByCreatorAndFolder')->willReturn($all);
        $repo->method('findByCreatorAndTag')->willReturn($all);
        $repo->method('findDeletedByCreator')->willReturn($all);

        $lister = $this->lister($repo);

        $result = $lister->list($this->user, page: 2, perPage: 3);
        self::assertSame([$all[3], $all[4], $all[5]], $result['items']);
        self::assertSame(7, $result['total']);

        self::assertSame([$all[6]], $lister->list($this->user, 'folder-1', page: 3, perPage: 3)['items']);
        self::assertSame([], $lister->list($this->user, tagId: 'tag', page: 9, perPage: 3)['items']);
        self::assertSame(7, $lister->list($this->user, trashOnly: true, page: 9, perPage: 3)['total']);

        // perPage null keeps the full list (pre-pagination behaviour).
        self::assertSame($all, $lister->list($this->user)['items']);
    }

    public function testSharedItemsAreSlicedInPhp(): void
    {
        $shared    = $this->items(4);
        $grantRepo = $this->createMock(VaultGrantRepositoryInterface::class);
        $grantRepo->method('findGrantedResourceIds')->willReturn(array_map(static fn (VaultItem $item): string => $item->getId(), $shared));
        $sharedRepo = $this->createMock(VaultItemRepositoryInterface::class);
        $sharedRepo->method('findByIdsForViewer')->willReturn($shared);

        /** @var MockObject&VaultItemPaginatorInterface&VaultItemRepositoryInterface $repo */
        $repo = $this->createMockForIntersectionOfInterfaces([VaultItemRepositoryInterface::class, VaultItemPaginatorInterface::class]);
        $repo->expects(self::never())->method('paginateByCreator');

        $lister = new VaultItemLister(
            $repo,
            new VaultSharedItemResolver($grantRepo, $sharedRepo, new NullVaultTeamMembershipResolver()),
            new EventDispatcher(),
        );

        $result = $lister->list($this->user, sharedOnly: true, page: 2, perPage: 3);
        self::assertSame([$shared[3]], $result['items']);
        self::assertSame(4, $result['total']);
    }

    public function testQueryOverrideIsReturnedAsGiven(): void
    {
        $override = $this->items(5);
        /** @var MockObject&VaultItemPaginatorInterface&VaultItemRepositoryInterface $repo */
        $repo = $this->createMockForIntersectionOfInterfaces([VaultItemRepositoryInterface::class, VaultItemPaginatorInterface::class]);
        $repo->expects(self::never())->method('paginateByCreator');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(VaultEvents::ITEM_LIST_QUERY, static function (VaultItemListQueryEvent $event) use ($override): void {
            $event->overrideResult($override, 50);
        });
        $lister = new VaultItemLister($repo, $this->sharedResolver(), $dispatcher);

        $result = $lister->list($this->user, page: 2, perPage: 2);
        self::assertSame($override, $result['items']);
        self::assertSame(50, $result['total']);
    }

    private function lister(VaultItemRepositoryInterface $repo): VaultItemLister
    {
        return new VaultItemLister($repo, $this->sharedResolver(), new EventDispatcher());
    }

    private function sharedResolver(): VaultSharedItemResolver
    {
        return new VaultSharedItemResolver(
            $this->createMock(VaultGrantRepositoryInterface::class),
            $this->createMock(VaultItemRepositoryInterface::class),
            new NullVaultTeamMembershipResolver(),
        );
    }

    /**
     * @return list<VaultItem>
     */
    private function items(int $count): array
    {
        return array_map(
            fn (int $i): VaultItem => new VaultItem(VaultItemType::Login, 'Item ' . $i, $this->user, 'ciphertext'),
            range(1, $count),
        );
    }
}
