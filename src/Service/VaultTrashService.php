<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Moves items and folders to the trash, restores and purges them.
 *
 * Since 1.16.0 purging an item or folder deletes its grants in the same transaction (no orphan grants) and emits
 * GRANT_REVOKED (reason `resource_purged`) per grant; item changes emit ITEM_CHANGED.
 */
final readonly class VaultTrashService
{
    /**
     * Stores the item and folder repositories, the optional logger and the optional 1.16.0 collaborators.
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultFolderRepositoryInterface $folderRepository,
        private ?LoggerInterface $logger = null,
        private ?VaultGrantExpiryRepositoryInterface $grantRepository = null,
        private ?VaultGrantService $grantService = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Soft-deletes an item.
     */
    public function moveItemToTrash(VaultItem $item, ?object $actor = null): void
    {
        $item->markDeleted();
        $this->itemRepository->save($item);
        $this->itemChanged($item, VaultItemChangeEvent::KIND_TRASHED, $actor);
    }

    /**
     * Restores a soft-deleted item.
     */
    public function restoreItem(VaultItem $item, ?object $actor = null): void
    {
        $item->restore();
        $this->itemRepository->save($item);
        $this->itemChanged($item, VaultItemChangeEvent::KIND_RESTORED, $actor);
    }

    /**
     * Permanently deletes an item (and, since 1.16.0, its grants in the same transaction).
     */
    public function purgeItem(VaultItem $item, ?object $actor = null): void
    {
        $id       = $item->getId();
        $ownerKey = $item->getOwner()->key();
        $grants   = $this->purge($item);
        $this->logger()->info('Vault item purged.', ['bundle' => 'nowo_vault', 'action' => 'item_purge', 'item_id' => $id]);
        $this->grantsPurged($grants, $ownerKey);
        $this->eventDispatcher?->dispatch(
            new VaultItemChangeEvent($actor !== null ? UserIdResolver::getId($actor) : null, $id, $ownerKey, VaultItemChangeEvent::KIND_PURGED, false, $this->now()),
            VaultEvents::ITEM_CHANGED,
        );
    }

    /**
     * Soft-deletes a folder after detaching its active items; returns how many were detached.
     */
    public function moveFolderToTrash(VaultFolder $folder): int
    {
        $detached = $this->itemRepository->detachItemsFromFolder($folder);
        $folder->markDeleted();
        $this->folderRepository->save($folder);

        return $detached;
    }

    /**
     * Restores a soft-deleted folder.
     */
    public function restoreFolder(VaultFolder $folder): void
    {
        $folder->restore();
        $this->folderRepository->save($folder);
    }

    /**
     * Permanently deletes a folder (and, since 1.16.0, its grants in the same transaction).
     */
    public function purgeFolder(VaultFolder $folder): void
    {
        $id       = $folder->getId();
        $ownerKey = $folder->getOwner()->key();
        $grants   = $this->purge($folder);
        $this->logger()->info('Vault folder purged.', ['bundle' => 'nowo_vault', 'action' => 'folder_purge', 'folder_id' => $id]);
        $this->grantsPurged($grants, $ownerKey);
    }

    /**
     * Deletes the resource, with its grants when the 1.16.0 grant repository is wired.
     *
     * @return list<VaultGrant>
     */
    private function purge(VaultItem|VaultFolder $resource): array
    {
        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            return $this->grantRepository->purgeResourceWithGrants($resource);
        }

        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $resource instanceof VaultItem ? $this->itemRepository->remove($resource) : $this->folderRepository->remove($resource);

        return [];
    }

    /**
     * @param list<VaultGrant> $grants
     */
    private function grantsPurged(array $grants, string $ownerKey): void
    {
        foreach ($grants as $grant) {
            $this->grantService?->dispatchChange(VaultEvents::GRANT_REVOKED, $grant, null, VaultGrantChangeEvent::REASON_RESOURCE_PURGED, ownerKey: $ownerKey);
        }
    }

    private function itemChanged(VaultItem $item, string $kind, ?object $actor): void
    {
        $this->eventDispatcher?->dispatch(
            new VaultItemChangeEvent($actor !== null ? UserIdResolver::getId($actor) : null, $item->getId(), $item->getOwner()->key(), $kind, false, $this->now()),
            VaultEvents::ITEM_CHANGED,
        );
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    /**
     * Returns the injected logger (REQ-OBS-001), or a no-op logger when none is wired.
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
