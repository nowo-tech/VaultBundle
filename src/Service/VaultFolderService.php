<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;

/**
 * Creates, renames and looks up vault folders.
 */
final readonly class VaultFolderService
{
    /**
     * Creates the service with the folder repository.
     */
    public function __construct(
        private VaultFolderRepositoryInterface $folderRepository,
    ) {
    }

    /**
     * Creates and persists a folder.
     */
    public function create(string $name, object $creator, ?VaultFolder $parent = null): VaultFolder
    {
        $folder = new VaultFolder($name, $creator, $parent);
        $this->folderRepository->save($folder);

        return $folder;
    }

    /**
     * Renames and persists a folder.
     */
    public function rename(VaultFolder $folder, string $name): VaultFolder
    {
        $folder->setName($name);
        $this->folderRepository->save($folder);

        return $folder;
    }

    /**
     * Returns the creator's active folders.
     *
     * @return list<VaultFolder>
     */
    public function listForCreator(object $creator): array
    {
        return $this->folderRepository->findByCreator($creator);
    }

    /**
     * Finds a folder by id.
     */
    public function find(string $id): ?VaultFolder
    {
        return $this->folderRepository->findById($id);
    }
}
