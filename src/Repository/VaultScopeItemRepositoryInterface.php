<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;

/**
 * Scope-owned item queries (1.16.0). Implemented by the Doctrine item repository.
 */
interface VaultScopeItemRepositoryInterface
{
    /**
     * One page of the scope's active items (tags fetch-joined), newest update first, and the total.
     *
     * @param list<string>|null $onlyIds restrict to these ids (members without a baseline see only granted items); null = every item
     *
     * @return array{items: list<VaultItem>, total: int}
     */
    public function paginateByScope(
        string $scope,
        ?array $onlyIds,
        ?string $folderId,
        ?string $tagId,
        ?string $search,
        int $offset,
        int $limit,
    ): array;

    /**
     * The scope's active items (optionally of one type), folders fetch-joined, in one query.
     *
     * @return list<VaultItem>
     */
    public function findActiveByScope(string $scope, ?VaultItemType $type = null): array;
}
