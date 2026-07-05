<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;

interface VaultItemRepositoryInterface
{
    public function save(VaultItem $item): void;

    public function remove(VaultItem $item): void;

    public function findById(string $id): ?VaultItem;

    /**
     * @return list<VaultItem>
     */
    public function findByCreator(object $creator, bool $includeDeleted = false): array;

    /**
     * @return list<VaultItem>
     */
    public function findByCreatorAndItemType(object $creator, VaultItemType $itemType, bool $includeDeleted = false): array;

    /**
     * @return list<VaultItem>
     */
    public function findByCreatorAndFolder(object $creator, ?string $folderId, bool $includeDeleted = false): array;

    /**
     * @return list<VaultItem>
     */
    public function findDeletedByCreator(object $creator): array;

    public function countByCreator(object $creator, bool $includeDeleted = false): int;

    /**
     * @return list<VaultItem>
     */
    public function searchByTitle(object $creator, string $query, int $limit = 50): array;

    /**
     * @return list<VaultItem>
     */
    public function findByCreatorAndTag(object $creator, string $tagId, ?string $folderId = null): array;

    /**
     * @return list<VaultItem>
     */
    public function searchByTitleOrTag(object $creator, string $query, int $limit = 50): array;

    /**
     * @param list<string> $ids
     *
     * @return list<VaultItem>
     */
    public function findByIdsForViewer(array $ids, object $viewer): array;

    /**
     * @param list<string> $ids
     *
     * @return list<VaultItem>
     */
    public function findByIdsForViewerAndItemType(array $ids, object $viewer, VaultItemType $itemType): array;

    /**
     * @return list<VaultItem>
     */
    public function findActiveByFolder(string $folderId): array;

    public function countActiveByFolder(string $folderId): int;

    /**
     * Removes folder association from active items; returns number of items updated.
     */
    public function detachItemsFromFolder(VaultFolder $folder): int;

    public function countAll(bool $includeDeleted = true): int;

    /**
     * @return list<VaultItem>
     */
    public function findBatch(int $offset, int $limit, bool $includeDeleted = true): array;

    /**
     * @param list<VaultItem> $items
     */
    public function saveBatch(array $items): void;
}
