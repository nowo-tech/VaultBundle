<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;

final readonly class VaultTrashService
{
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultFolderRepositoryInterface $folderRepository,
    ) {
    }

    public function moveItemToTrash(VaultItem $item): void
    {
        $item->markDeleted();
        $this->itemRepository->save($item);
    }

    public function restoreItem(VaultItem $item): void
    {
        $item->restore();
        $this->itemRepository->save($item);
    }

    public function purgeItem(VaultItem $item): void
    {
        $this->itemRepository->remove($item);
    }

    public function moveFolderToTrash(VaultFolder $folder): int
    {
        $detached = $this->itemRepository->detachItemsFromFolder($folder);
        $folder->markDeleted();
        $this->folderRepository->save($folder);

        return $detached;
    }

    public function restoreFolder(VaultFolder $folder): void
    {
        $folder->restore();
        $this->folderRepository->save($folder);
    }

    public function purgeFolder(VaultFolder $folder): void
    {
        $this->folderRepository->remove($folder);
    }
}
