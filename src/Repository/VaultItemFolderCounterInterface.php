<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

/**
 * Optional batch counter for the items page folder list (one GROUP BY instead of one COUNT per folder).
 *
 * Implemented by {@see DoctrineOrmVaultItemRepository}; a custom {@see VaultItemRepositoryInterface} that does not
 * implement it keeps working through {@see VaultItemRepositoryInterface::countActiveByFolder()} per folder.
 */
interface VaultItemFolderCounterInterface
{
    /**
     * Active (not trashed) item count per folder id; folders without items are 0.
     *
     * @param list<string> $folderIds
     *
     * @return array<string, int>
     */
    public function countActiveByFolders(array $folderIds): array;
}
