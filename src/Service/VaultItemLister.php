<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Event\VaultItemListResultEvent;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;

final readonly class VaultItemLister
{
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultSharedItemResolver $sharedItemResolver,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return array{items: list<VaultItem>, total: int}
     */
    public function list(
        UserInterface $viewer,
        ?string $folderId = null,
        bool $trashOnly = false,
        bool $sharedOnly = false,
        ?string $searchQuery = null,
        ?string $tagId = null,
    ): array {
        $queryEvent = new VaultItemListQueryEvent($viewer, $viewer, $folderId, $trashOnly, $sharedOnly);
        $this->eventDispatcher->dispatch($queryEvent, VaultEvents::ITEM_LIST_QUERY);

        if ($queryEvent->hasOverride()) {
            $items = $queryEvent->getOverrideItems();
            $total = $queryEvent->getOverrideTotal();
        } elseif ($sharedOnly) {
            $items = $this->sharedItemResolver->resolve($viewer);
            $total = count($items);
        } elseif ($trashOnly) {
            $items = $this->itemRepository->findDeletedByCreator($queryEvent->getListSubject());
            $total = count($items);
        } elseif ($searchQuery !== null && $searchQuery !== '') {
            $items = $this->itemRepository->searchByTitleOrTag($queryEvent->getListSubject(), $searchQuery);
            $total = count($items);
        } elseif ($tagId !== null && $tagId !== '') {
            $items = $this->itemRepository->findByCreatorAndTag($queryEvent->getListSubject(), $tagId, $folderId);
            $total = count($items);
        } elseif ($folderId !== null) {
            $items = $this->itemRepository->findByCreatorAndFolder($queryEvent->getListSubject(), $folderId);
            $total = count($items);
        } else {
            $items = $this->itemRepository->findByCreator($queryEvent->getListSubject());
            $total = count($items);
        }

        $resultEvent = new VaultItemListResultEvent($viewer, $items, $total);
        $this->eventDispatcher->dispatch($resultEvent, VaultEvents::ITEM_LIST_RESULT);

        return [
            'items' => $resultEvent->getItems(),
            'total' => $resultEvent->getTotal(),
        ];
    }
}
