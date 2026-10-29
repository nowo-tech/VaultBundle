<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultFolder;

/**
 * Persistence contract for vault folders.
 */
interface VaultFolderRepositoryInterface
{
    /**
     * Persists the folder.
     */
    public function save(VaultFolder $folder): void;

    /**
     * Removes the folder.
     */
    public function remove(VaultFolder $folder): void;

    /**
     * Finds a folder by id.
     */
    public function findById(string $id): ?VaultFolder;

    /**
     * Returns the creator's folders, optionally including trashed ones.
     *
     * @return list<VaultFolder>
     */
    public function findByCreator(object $creator, bool $includeDeleted = false): array;

    /**
     * Returns the creator's trashed folders.
     *
     * @return list<VaultFolder>
     */
    public function findDeletedByCreator(object $creator): array;
}
