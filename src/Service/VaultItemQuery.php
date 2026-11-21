<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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

use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function mb_strtolower;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * The vault's {@see VaultItemQueryInterface} (2.1.0): DQL on the vault's private entity manager, tags fetch-joined.
 *
 * Exactness (REQ-SEC-006, audit YF-L4): the owner id, the item ids and an exact title are compared by the database
 * and again byte for byte in PHP. A query first reads the ids of every candidate (one scalar row each, in order),
 * keeps the exact matches and pages them in PHP, then loads that page: the items, the page and the total all follow
 * the byte-exact rule. With the relation mapping and an integer user key, a non-canonical owner id matches nothing.
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
        $ids = $this->matchingIds($criteria);

        return new VaultItemPage(
            $this->findByIds(array_slice($ids, $criteria->offset, $criteria->limit)),
            count($ids),
            $criteria->offset,
            $criteria->limit,
        );
    }

    public function count(VaultItemCriteria $criteria): int
    {
        return count($this->matchingIds($criteria));
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
     * The ids of every matching item, in the criteria's order, after the byte-exact re-check of the owner id and the
     * exact title in PHP (audit YF-L4): one scalar row per candidate, so pages and totals agree with the promise.
     *
     * @return list<string>
     */
    private function matchingIds(VaultItemCriteria $criteria): array
    {
        if (!$this->ownerIdFitsTheMapping($criteria->ownerUserId)) {
            return [];
        }

        $owner = match (true) {
            $criteria->ownerScope !== null => 'i.ownerScope',
            $this->users->mapsById()       => 'i.creator',
            default                        => 'IDENTITY(i.creator)',
        };
        $qb = $this->filtered($criteria)->select('i.id AS id', 'i.title AS title', $owner . ' AS owner');
        $this->order($qb, $criteria->sort);

        $ids = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $id = (string) $row['id'];
            if (($criteria->ownerUserId === null || (string) $row['owner'] === $criteria->ownerUserId)
                && ($criteria->title === null || $row['title'] === $criteria->title)) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Whether a personal owner id can be a key of the user relation (audit YF-L4): with the relation mapping and an
     * integer key, only the canonical decimal form (`5`, never `05`, ` 5` or `abc`, which some databases cast or refuse).
     */
    private function ownerIdFitsTheMapping(?string $ownerUserId): bool
    {
        if ($ownerUserId === null || $this->users->mapsById()) {
            return true;
        }

        $target = $this->entityManager->getClassMetadata($this->entityManager->getClassMetadata(VaultItem::class)->getAssociationTargetClass('creator'));
        $type   = $target->getTypeOfField($target->getSingleIdentifierFieldName());

        return !in_array($type, ['integer', 'smallint', 'bigint'], true) || preg_match('/^(0|-?[1-9][0-9]{0,18})$/D', $ownerUserId) === 1;
    }
}
