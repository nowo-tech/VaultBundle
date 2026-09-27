<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use SortDirection;

use function count;

final readonly class DoctrineOrmVaultItemRepository implements VaultItemRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    public function save(VaultItem $item): void
    {
        $this->em()->persist($item);
        $this->em()->flush();
    }

    public function remove(VaultItem $item): void
    {
        $this->em()->remove($item);
        $this->em()->flush();
    }

    public function findById(string $id): ?VaultItem
    {
        return $this->em()->find(VaultItem::class, $id);
    }

    public function findByCreator(object $creator, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('i.updatedAt', SortDirection::Descending);

        if (!$includeDeleted) {
            $qb->andWhere('i.deletedAt IS NULL');
        }

        /* @var list<VaultItem> */
        return $qb->getQuery()->getResult();
    }

    public function findByCreatorAndItemType(object $creator, VaultItemType $itemType, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
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

    public function findByCreatorAndFolder(object $creator, ?string $folderId, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
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

    public function saveBatch(array $items): void
    {
        foreach ($items as $item) {
            $this->em()->persist($item);
        }

        if ($items !== []) {
            $this->em()->flush();
        }
    }
}
