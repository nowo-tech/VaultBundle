<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;

/**
 * Persistence contract for vault items.
 */
interface VaultItemRepositoryInterface
{
    /**
     * Persists the item.
     */
    public function save(VaultItem $item): void;

    /**
     * Removes the item permanently.
     */
    public function remove(VaultItem $item): void;

    /**
     * Finds an item by id.
     */
    public function findById(string $id): ?VaultItem;

    /**
     * Returns the creator's items, optionally including trashed ones.
     *
     * @return list<VaultItem>
     */
    public function findByCreator(object $creator, bool $includeDeleted = false): array;

    /**
     * Returns the creator's items of one type, optionally including trashed ones.
     *
     * @return list<VaultItem>
     */
    public function findByCreatorAndItemType(object $creator, VaultItemType $itemType, bool $includeDeleted = false): array;

    /**
     * Returns the creator's items in a folder (null = no folder), optionally including trashed ones.
     *
     * @return list<VaultItem>
     */
    public function findByCreatorAndFolder(object $creator, ?string $folderId, bool $includeDeleted = false): array;

    /**
     * Returns the creator's trashed items.
     *
     * @return list<VaultItem>
     */
    public function findDeletedByCreator(object $creator): array;

    /**
     * Counts the creator's items, optionally including trashed ones.
     */
    public function countByCreator(object $creator, bool $includeDeleted = false): int;

    /**
     * Searches the creator's active items by title (at most limit results).
     *
     * @return list<VaultItem>
     */
    public function searchByTitle(object $creator, string $query, int $limit = 50): array;

    /**
     * Returns the creator's active items carrying a tag, optionally within a folder.
     *
     * @return list<VaultItem>
     */
    public function findByCreatorAndTag(object $creator, string $tagId, ?string $folderId = null): array;

    /**
     * Searches the creator's active items by title or tag name (at most limit results).
     *
     * @return list<VaultItem>
     */
    public function searchByTitleOrTag(object $creator, string $query, int $limit = 50): array;

    /**
     * Returns the active items with the given ids that the viewer did not create.
     *
     * @param list<string> $ids
     *
     * @return list<VaultItem>
     */
    public function findByIdsForViewer(array $ids, object $viewer): array;

    /**
     * Returns the active items of one type with the given ids that the viewer did not create.
     *
     * @param list<string> $ids
     *
     * @return list<VaultItem>
     */
    public function findByIdsForViewerAndItemType(array $ids, object $viewer, VaultItemType $itemType): array;

    /**
     * Returns the active items in a folder.
     *
     * @return list<VaultItem>
     */
    public function findActiveByFolder(string $folderId): array;

    /**
     * Counts the active items in a folder.
     */
    public function countActiveByFolder(string $folderId): int;

    /**
     * Removes folder association from active items; returns number of items updated.
     */
    public function detachItemsFromFolder(VaultFolder $folder): int;

    /**
     * Counts all items, optionally including trashed ones.
     */
    public function countAll(bool $includeDeleted = true): int;

    /**
     * Returns a batch of items (offset/limit), e.g. for re-encryption.
     *
     * @return list<VaultItem>
     */
    public function findBatch(int $offset, int $limit, bool $includeDeleted = true): array;

    /**
     * Persists a batch of items with one flush.
     *
     * @param list<VaultItem> $items
     */
    public function saveBatch(array $items): void;
}
