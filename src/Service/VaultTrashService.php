<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Moves items and folders to the trash, restores and purges them.
 */
final readonly class VaultTrashService
{
    /**
     * Stores the item and folder repositories and the optional logger.
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultFolderRepositoryInterface $folderRepository,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Soft-deletes an item.
     */
    public function moveItemToTrash(VaultItem $item): void
    {
        $item->markDeleted();
        $this->itemRepository->save($item);
    }

    /**
     * Restores a soft-deleted item.
     */
    public function restoreItem(VaultItem $item): void
    {
        $item->restore();
        $this->itemRepository->save($item);
    }

    /**
     * Permanently deletes an item.
     */
    public function purgeItem(VaultItem $item): void
    {
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->itemRepository->remove($item);
        $this->logger()->info('Vault item purged.', ['bundle' => 'nowo_vault', 'action' => 'item_purge', 'item_id' => $item->getId()]);
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
     * Permanently deletes a folder.
     */
    public function purgeFolder(VaultFolder $folder): void
    {
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->folderRepository->remove($folder);
        $this->logger()->info('Vault folder purged.', ['bundle' => 'nowo_vault', 'action' => 'folder_purge', 'folder_id' => $folder->getId()]);
    }

    /**
     * Returns the injected logger (REQ-OBS-001), or a no-op logger when none is wired.
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
