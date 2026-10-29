<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Service\VaultItemLister;

/**
 * Optional database-level pagination for the manage item lists (REQ-PERF-001).
 *
 * Implemented by {@see DoctrineOrmVaultItemRepository}. A custom {@see VaultItemRepositoryInterface} that does not
 * implement it keeps working: {@see VaultItemLister} then loads the full list through the
 * base interface and slices the requested page in PHP.
 */
interface VaultItemPaginatorInterface
{
    /**
     * Returns one page of the creator's items plus the total number of matching items.
     *
     * Filters mirror the unpaginated lister branches: $trashOnly lists soft-deleted items (newest deletion first,
     * folder and tag ignored); otherwise active items, restricted to $tagId (and $folderId when also given) or to
     * $folderId alone, newest update first.
     *
     * @return array{items: list<VaultItem>, total: int}
     */
    public function paginateByCreator(
        object $creator,
        ?string $folderId,
        ?string $tagId,
        bool $trashOnly,
        int $offset,
        int $limit,
    ): array;
}
