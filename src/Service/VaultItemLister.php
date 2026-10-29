<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Event\VaultItemListResultEvent;
use Nowo\VaultBundle\Repository\VaultItemPaginatorInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function count;
use function max;

/**
 * Resolves the item list shown on the items / shared / trash pages, honouring list query/result events.
 */
final readonly class VaultItemLister
{
    /**
     * Stores the item repository, the shared-item resolver and the event dispatcher used for list overrides.
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultSharedItemResolver $sharedItemResolver,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Lists the viewer's items for one page (REQ-PERF-001).
     *
     * With $perPage null the whole list is returned (pre-1.14 behaviour). With a page size, own / trash / folder /
     * tag lists are paginated in the database when the repository implements {@see VaultItemPaginatorInterface}
     * (otherwise sliced in PHP); shared items are sliced in PHP; search stays capped by the repository (50).
     * Items returned by a {@see VaultItemListQueryEvent} override are used as given (the listener owns paging).
     *
     * @return array{items: list<VaultItem>, total: int}
     */
    public function list(
        UserInterface $viewer,
        ?string $folderId = null,
        bool $trashOnly = false,
        bool $sharedOnly = false,
        ?string $searchQuery = null,
        ?string $tagId = null,
        int $page = 1,
        ?int $perPage = null,
    ): array {
        $queryEvent = new VaultItemListQueryEvent($viewer, $viewer, $folderId, $trashOnly, $sharedOnly);
        $this->eventDispatcher->dispatch($queryEvent, VaultEvents::ITEM_LIST_QUERY);

        $offset    = $perPage === null ? 0 : (max(1, $page) - 1) * $perPage;
        $isSearch  = $searchQuery !== null && $searchQuery !== '';
        $tagFilter = $tagId !== null && $tagId !== '' ? $tagId : null;

        if ($queryEvent->hasOverride()) {
            $items = $queryEvent->getOverrideItems();
            $total = $queryEvent->getOverrideTotal();
        } elseif (
            $perPage !== null
            && !$sharedOnly
            && !$isSearch
            && $this->itemRepository instanceof VaultItemPaginatorInterface
        ) {
            $pageResult = $this->itemRepository->paginateByCreator(
                $queryEvent->getListSubject(),
                $trashOnly ? null : $folderId,
                $trashOnly ? null : $tagFilter,
                $trashOnly,
                $offset,
                $perPage,
            );
            $items = $pageResult['items'];
            $total = $pageResult['total'];
        } else {
            if ($sharedOnly) {
                $items = $this->sharedItemResolver->resolve($viewer);
            } elseif ($trashOnly) {
                $items = $this->itemRepository->findDeletedByCreator($queryEvent->getListSubject());
            } elseif ($isSearch) {
                $items = $this->itemRepository->searchByTitleOrTag($queryEvent->getListSubject(), $searchQuery);
            } elseif ($tagFilter !== null) {
                $items = $this->itemRepository->findByCreatorAndTag($queryEvent->getListSubject(), $tagFilter, $folderId);
            } elseif ($folderId !== null) {
                $items = $this->itemRepository->findByCreatorAndFolder($queryEvent->getListSubject(), $folderId);
            } else {
                $items = $this->itemRepository->findByCreator($queryEvent->getListSubject());
            }

            $total = count($items);
            if ($perPage !== null) {
                $items = array_slice($items, $offset, $perPage);
            }
        }

        $resultEvent = new VaultItemListResultEvent($viewer, $items, $total);
        $this->eventDispatcher->dispatch($resultEvent, VaultEvents::ITEM_LIST_RESULT);

        return [
            'items' => $resultEvent->getItems(),
            'total' => $resultEvent->getTotal(),
        ];
    }
}
