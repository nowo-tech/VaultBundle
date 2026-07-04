<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultFolder;

interface VaultFolderRepositoryInterface
{
    public function save(VaultFolder $folder): void;

    public function remove(VaultFolder $folder): void;

    public function findById(string $id): ?VaultFolder;

    /**
     * @return list<VaultFolder>
     */
    public function findByCreator(object $creator, bool $includeDeleted = false): array;

    /**
     * @return list<VaultFolder>
     */
    public function findDeletedByCreator(object $creator): array;
}
