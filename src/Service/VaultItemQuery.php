<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use InvalidArgumentException;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemSort;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultTrashFilter;
use Nowo\VaultBundle\Internal\VaultViews;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\View\VaultItemPage;
use Nowo\VaultBundle\View\VaultItemView;

use function array_filter;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function iterator_to_array;
use function mb_strtolower;
use function sprintf;
use function str_replace;

/**
 * The vault's {@see VaultItemQueryInterface} (2.1.0): DQL on the vault's private entity manager, tags fetch-joined.
 *
 * Exactness (REQ-SEC-006): the owner id, the item ids and an exact title are compared by the database (binary NO PAD
 * collation on the MySQL / MariaDB id columns) and again byte for byte in PHP on the rows returned. With an exact title
 * the matching rows of the owner are read once and paged in PHP, so the total is exact too.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the private manager is resolved on every call and dropped at
 * every boundary.
 */
final readonly class VaultItemQuery implements VaultItemQueryInterface
{
    /**
     * @param EntityManagerInterface $entityManager the vault's private manager
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VaultUserReferenceResolverInterface $users,
    ) {
    }

    public function find(VaultItemCriteria $criteria): VaultItemPage
    {
        $qb = $this->filtered($criteria)->select('i')->leftJoin('i.tags', 't')->addSelect('t');
        $this->order($qb, $criteria->sort);

        if ($criteria->title !== null) {
            /** @var list<VaultItem> $all */
            $all   = $qb->getQuery()->getResult();
            $exact = $this->exact($all, $criteria);

            return new VaultItemPage(
                array_map(VaultViews::item(...), array_slice($exact, $criteria->offset, $criteria->limit)),
                count($exact),
                $criteria->offset,
                $criteria->limit,
            );
        }

        // @igor-ignore - A query builder built for this call (createQueryBuilder() returns a new one), not shared state.
        $qb->setFirstResult($criteria->offset)->setMaxResults($criteria->limit);
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);
        /** @var list<VaultItem> $items */
        $items = iterator_to_array($paginator, false);

        return new VaultItemPage(
            array_map(VaultViews::item(...), $this->exact($items, $criteria)),
            count($paginator),
            $criteria->offset,
            $criteria->limit,
        );
    }

    public function count(VaultItemCriteria $criteria): int
    {
        if ($criteria->title !== null) {
            $rows = $this->filtered($criteria)->select('i.title AS title')->getQuery()->getArrayResult();

            return count(array_filter($rows, static fn (array $row): bool => $row['title'] === $criteria->title));
        }

        return (int) $this->filtered($criteria)->select('COUNT(DISTINCT i.id)')->getQuery()->getSingleScalarResult();
    }

    public function findById(string $itemId): ?VaultItemView
    {
        return $this->findByIds([$itemId])[0] ?? null;
    }

    public function findByIds(array $itemIds): array
    {
        if (count($itemIds) > VaultItemCriteria::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('At most %d item ids per call.', VaultItemCriteria::MAX_LIMIT));
        }

        $ids = array_values(array_unique($itemIds));
        if ($ids === []) {
            return [];
        }

        /** @var list<VaultItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('i', 't')
            ->from(VaultItem::class, 'i')
            ->leftJoin('i.tags', 't')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($items as $item) {
            $byId[$item->getId()] = $item;
        }

        $views = [];
        foreach ($ids as $id) {
            // Exact id (REQ-SEC-006): a case or padding variant of an id the database matched is not this item.
            if (isset($byId[$id])) {
                $views[] = VaultViews::item($byId[$id]);
            }
        }

        return $views;
    }

    /**
     * The query over the criteria's owner and filters (no select, no order).
     */
    private function filtered(VaultItemCriteria $criteria): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()->from(VaultItem::class, 'i');

        if ($criteria->ownerScope !== null) {
            $qb->where('i.ownerScope = :scope')->setParameter('scope', $criteria->ownerScope);
        } else {
            // id mapping: the creator column holds the id; relation mapping: the foreign key of the user relation.
            $qb->where('i.ownerScope IS NULL')
                ->andWhere($this->users->mapsById() ? 'i.creator = :owner' : 'IDENTITY(i.creator) = :owner')
                ->setParameter('owner', $criteria->ownerUserId);
        }

        if ($criteria->type instanceof VaultItemType) {
            $qb->andWhere('i.itemType = :type')->setParameter('type', $criteria->type);
        }
        if ($criteria->folderId !== null) {
            $qb->andWhere('IDENTITY(i.folder) = :folder')->setParameter('folder', $criteria->folderId);
        } elseif ($criteria->withoutFolder) {
            $qb->andWhere('i.folder IS NULL');
        }
        if ($criteria->tagId !== null) {
            // Separate alias: filtering on the fetch-joined alias would hydrate only the matching tag.
            $qb->innerJoin('i.tags', 'ft', 'WITH', 'ft.id = :tag')->setParameter('tag', $criteria->tagId);
        }
        if ($criteria->title !== null) {
            $qb->andWhere('i.title = :title')->setParameter('title', $criteria->title);
        }
        if ($criteria->titleContains !== null) {
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($criteria->titleContains));
            $qb->andWhere("LOWER(i.title) LIKE :contains ESCAPE '!'")->setParameter('contains', '%' . $needle . '%');
        }

        match ($criteria->trash) {
            VaultTrashFilter::Active  => $qb->andWhere('i.deletedAt IS NULL'),
            VaultTrashFilter::Trashed => $qb->andWhere('i.deletedAt IS NOT NULL'),
            VaultTrashFilter::Any     => $qb,
        };

        return $qb;
    }

    private function order(QueryBuilder $qb, VaultItemSort $sort): void
    {
        match ($sort) {
            VaultItemSort::UpdatedDesc => $qb->orderBy('i.updatedAt', 'DESC'),
            VaultItemSort::CreatedAsc  => $qb->orderBy('i.createdAt', 'ASC'),
            VaultItemSort::CreatedDesc => $qb->orderBy('i.createdAt', 'DESC'),
            VaultItemSort::TitleAsc    => $qb->orderBy('i.title', 'ASC'),
        };
        // Stable order across pages when the sort values tie.
        $qb->addOrderBy('i.id', 'ASC');
    }

    /**
     * The items whose owner id and title (when the criteria name one) equal the criteria's byte for byte.
     *
     * @param list<VaultItem> $items
     *
     * @return list<VaultItem>
     */
    private function exact(array $items, VaultItemCriteria $criteria): array
    {
        return array_values(array_filter($items, static fn (VaultItem $item): bool => ($criteria->ownerUserId === null || $item->getCreatorId() === $criteria->ownerUserId)
            && ($criteria->title === null || $item->getTitle() === $criteria->title)));
    }
}
