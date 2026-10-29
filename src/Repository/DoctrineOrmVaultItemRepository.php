<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use SortDirection;

use function array_fill_keys;
use function count;
use function iterator_to_array;
use function max;

/**
 * Doctrine ORM repository of vault items.
 */
final readonly class DoctrineOrmVaultItemRepository implements VaultItemRepositoryInterface, VaultItemFolderCounterInterface, VaultItemPaginatorInterface
{
    use ResolvesEntityManagerTrait;

    /**
     * Stores the entity manager and the optional registry / manager name.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    /**
     * Persists and flushes an item.
     */
    public function save(VaultItem $item): void
    {
        $this->em()->persist($item);
        $this->em()->flush();
    }

    /**
     * Removes and flushes an item.
     */
    public function remove(VaultItem $item): void
    {
        $this->em()->remove($item);
        $this->em()->flush();
    }

    /**
     * Returns the item with the given id, or null.
     */
    public function findById(string $id): ?VaultItem
    {
        return $this->em()->find(VaultItem::class, $id);
    }

    /**
     * Returns the creator's items (tags fetch-joined), newest update first.
     */
    public function findByCreator(object $creator, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            // The items page loops item.tags: fetch-join (no LIMIT here) instead of one lazy load per item.
            ->leftJoin('i.tags', 't')->addSelect('t')
            ->where('i.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('i.updatedAt', SortDirection::Descending);

        if (!$includeDeleted) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        /* @var list<VaultItem> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the creator's items of one type (tags fetch-joined), newest update first.
     */
    public function findByCreatorAndItemType(object $creator, VaultItemType $itemType, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            // The items page loops item.tags: fetch-join (no LIMIT here) instead of one lazy load per item.
            ->leftJoin('i.tags', 't')->addSelect('t')
            ->where('i.creator = :creator')
            ->andWhere('i.itemType = :itemType')
            ->setParameter('creator', $creator)
            ->setParameter('itemType', $itemType)
            ->orderBy('i.updatedAt', SortDirection::Descending);

        if (!$includeDeleted) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        /* @var list<VaultItem> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the creator's items in a folder (null = root), newest update first.
     */
    public function findByCreatorAndFolder(object $creator, ?string $folderId, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            // The items page loops item.tags: fetch-join (no LIMIT here) instead of one lazy load per item.
            ->leftJoin('i.tags', 't')->addSelect('t')
            ->where('i.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('i.updatedAt', SortDirection::Descending);

        if ($folderId === null) {
            $qb->andWhere('i.folder IS NULL');
        } else {
            $qb->andWhere('i.folder = :folderId')->setParameter('folderId', $folderId);
        }

        if (!$includeDeleted) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        /* @var list<VaultItem> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the creator's trashed items, newest deletion first.
     */
    public function findDeletedByCreator(object $creator): array
    {
        /* @var list<VaultItem> */
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.creator = :creator')
            ->andWhere('i.deletedAt IS NOT NULL')
            ->setParameter('creator', $creator)
            ->orderBy('i.deletedAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts the creator's items.
     */
    public function countByCreator(object $creator, bool $includeDeleted = false): int
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(VaultItem::class, 'i')
            ->where('i.creator = :creator')
            ->setParameter('creator', $creator);

        if (!$includeDeleted) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns up to $limit of the creator's active items whose title contains $query.
     */
    public function searchByTitle(object $creator, string $query, int $limit = 50): array
    {
        /* @var list<VaultItem> */
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.creator = :creator')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('LOWER(i.title) LIKE :q')
            ->setParameter('creator', $creator)
            ->setParameter('q', '%' . mb_strtolower($query) . '%')
            ->setMaxResults($limit)
            ->orderBy('i.title', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the creator's active items carrying a tag, optionally restricted to a folder.
     */
    public function findByCreatorAndTag(object $creator, string $tagId, ?string $folderId = null): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->innerJoin('i.tags', 't')
            ->where('i.creator = :creator')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('t.id = :tagId')
            ->setParameter('creator', $creator)
            ->setParameter('tagId', $tagId)
            ->orderBy('i.updatedAt', SortDirection::Descending);

        if ($folderId !== null && $folderId !== '') {
            $qb->andWhere('i.folder = :folderId')->setParameter('folderId', $folderId);
        }

        /* @var list<VaultItem> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Returns up to $limit of the creator's active items whose title or a tag name contains $query.
     */
    public function searchByTitleOrTag(object $creator, string $query, int $limit = 50): array
    {
        $needle = '%' . mb_strtolower($query) . '%';

        /* @var list<VaultItem> */
        return $this->em()->createQueryBuilder()
            ->select('DISTINCT i')
            ->from(VaultItem::class, 'i')
            ->leftJoin('i.tags', 't')
            ->where('i.creator = :creator')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('LOWER(i.title) LIKE :q OR LOWER(t.name) LIKE :q')
            ->setParameter('creator', $creator)
            ->setParameter('q', $needle)
            ->setMaxResults($limit)
            ->orderBy('i.title', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the active items with the given ids that were not created by the viewer.
     */
    public function findByIdsForViewer(array $ids, object $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        /* @var list<VaultItem> */
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.id IN (:ids)')
            ->andWhere('i.creator != :viewer')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->setParameter('viewer', $viewer)
            ->orderBy('i.title', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the active items of one type with the given ids that were not created by the viewer.
     */
    public function findByIdsForViewerAndItemType(array $ids, object $viewer, VaultItemType $itemType): array
    {
        if ($ids === []) {
            return [];
        }

        /* @var list<VaultItem> */
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.id IN (:ids)')
            ->andWhere('i.creator != :viewer')
            ->andWhere('i.itemType = :itemType')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->setParameter('viewer', $viewer)
            ->setParameter('itemType', $itemType)
            ->orderBy('i.title', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the active items of a folder.
     */
    public function findActiveByFolder(string $folderId): array
    {
        /* @var list<VaultItem> */
        return $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.folder = :folderId')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('folderId', $folderId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the active item count per folder id with one GROUP BY query.
     */
    public function countActiveByFolders(array $folderIds): array
    {
        $counts = array_fill_keys($folderIds, 0);
        if ($folderIds === []) {
            return $counts;
        }

        /** @var list<array{folderId: string, n: int|string}> $rows */
        $rows = $this->em()->createQueryBuilder()
            ->select('IDENTITY(i.folder) AS folderId', 'COUNT(i.id) AS n')
            ->from(VaultItem::class, 'i')
            ->where('i.folder IN (:folderIds)')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('folderIds', $folderIds)
            ->groupBy('i.folder')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(string) $row['folderId']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Counts the active items of a folder.
     */
    public function countActiveByFolder(string $folderId): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(VaultItem::class, 'i')
            ->where('i.folder = :folderId')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('folderId', $folderId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Moves the active items of a folder to the root and returns how many were moved.
     */
    public function detachItemsFromFolder(VaultFolder $folder): int
    {
        $items = $this->findActiveByFolder($folder->getId());
        foreach ($items as $item) {
            $item->setFolder(null);
            $this->em()->persist($item);
        }

        if ($items !== []) {
            $this->em()->flush();
        }

        return count($items);
    }

    /**
     * Counts all items, optionally including trashed ones.
     */
    public function countAll(bool $includeDeleted = true): int
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(VaultItem::class, 'i');

        if (!$includeDeleted) {
            $qb->where('i.deletedAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Returns a batch of items ordered by id (used by re-encryption).
     */
    public function findBatch(int $offset, int $limit, bool $includeDeleted = true): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->orderBy('i.id', SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        if (!$includeDeleted) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        /* @var list<VaultItem> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Persists a batch of items with one flush.
     */
    public function saveBatch(array $items): void
    {
        foreach ($items as $item) {
            $this->em()->persist($item);
        }

        if ($items !== []) {
            $this->em()->flush();
        }
    }

    /**
     * Returns one page of the creator's items (tags fetch-joined) and the total, using Doctrine's Paginator so the
     * LIMIT applies to items, not to joined tag rows.
     */
    public function paginateByCreator(
        object $creator,
        ?string $folderId,
        ?string $tagId,
        bool $trashOnly,
        int $offset,
        int $limit,
    ): array {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->leftJoin('i.tags', 't')->addSelect('t')
            ->where('i.creator = :creator')
            ->setParameter('creator', $creator);

        if ($trashOnly) {
            $qb->andWhere('i.deletedAt IS NOT NULL')->orderBy('i.deletedAt', SortDirection::Descending);
        } else {
            $qb->andWhere('i.deletedAt IS NULL')->orderBy('i.updatedAt', SortDirection::Descending);

            if ($tagId !== null && $tagId !== '') {
                // Separate alias: filtering on the fetch-joined alias would hydrate only the matching tag.
                $qb->innerJoin('i.tags', 'ft')->andWhere('ft.id = :tagId')->setParameter('tagId', $tagId);
            }

            if ($folderId !== null && $folderId !== '') {
                $qb->andWhere('i.folder = :folderId')->setParameter('folderId', $folderId);
            }
        }

        // Stable order across pages when timestamps tie.
        $qb->addOrderBy('i.id', SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);

        /** @var list<VaultItem> $items */
        $items = iterator_to_array($paginator, false);

        return ['items' => $items, 'total' => count($paginator)];
    }
}
