<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use Nowo\VaultBundle\Doctrine\ExactCollation;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemSort;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultTrashFilter;
use Nowo\VaultBundle\Internal\VaultViews;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\View\VaultItemPage;
use Nowo\VaultBundle\View\VaultItemView;
use Throwable;

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
 * SQL paging (2.1.1, audit YF-D1): when the database itself compares the owner byte for byte and there is no exact
 * title filter, the page is read with SQL LIMIT/OFFSET and the total with SQL COUNT instead of one row per candidate:
 * a scope owner (scope keys are `[a-z0-9_-]` only), or the id mapping whose live `creator_id` column compares exactly
 * (MySQL `utf8mb4_0900_bin`, MariaDB `utf8mb4_nopad_bin`, a deterministic PostgreSQL collation outside a transaction,
 * SQLite; SQL Server, Oracle and other platforms never). The page and the total are two statements (the total is
 * skipped on a short last page): without a transaction around them, a concurrent write may land between them. The page's owners are still re-checked with `===` in PHP; a page row that fails
 * the re-check (a column altered by hand) sends the call to the exact path above, so a query never returns fewer
 * exact results than the exact path. Everything else (relation mapping, an exact title) keeps the exact path.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the private manager is resolved on every call and dropped at
 * every boundary.
 */
final readonly class VaultItemQuery implements VaultItemQueryInterface
{
    /** The collation of one column of the current MySQL / MariaDB database (table, column). */
    private const MYSQL_COLLATION_SQL = "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND DATA_TYPE = 'varchar'";

    /** Whether one PostgreSQL column (table on the search path, column) is varchar / text with a deterministic collation. */
    private const POSTGRESQL_DETERMINISTIC_SQL = "SELECT c.collisdeterministic AND a.atttypid IN ('varchar'::regtype, 'text'::regtype) FROM pg_attribute a JOIN pg_collation c ON c.oid = a.attcollation WHERE a.attrelid = to_regclass(?) AND a.attname = ? AND NOT a.attisdropped";

    /**
     * @param EntityManagerInterface $entityManager the vault's private manager
     * @param bool $sqlPaging false keeps every call on the exact path (the tests compare both
     *                        paths); hosts keep the default
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VaultUserReferenceResolverInterface $users,
        private bool $sqlPaging = true,
    ) {
    }

    public function find(VaultItemCriteria $criteria): VaultItemPage
    {
        $page = $this->exactInSql($criteria) ? $this->sqlPage($criteria) : null;
        if ($page instanceof VaultItemPage) {
            return $page;
        }

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
        if ($this->exactInSql($criteria)) {
            return $this->sqlCount($criteria);
        }

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
     * The owner column of the criteria in DQL: the scope, the id-mapped creator column or the relation's foreign key.
     */
    private function ownerExpression(VaultItemCriteria $criteria): string
    {
        return match (true) {
            $criteria->ownerScope !== null => 'i.ownerScope',
            $this->users->mapsById()       => 'i.creator',
            default                        => 'IDENTITY(i.creator)',
        };
    }

    /**
     * Whether SQL already compares the owner byte for byte, so SQL may page and count (audit YF-D1): no exact title
     * (its PHP re-check would thin the page) and either a scope owner or the id mapping on an exact column.
     */
    private function exactInSql(VaultItemCriteria $criteria): bool
    {
        if (!$this->sqlPaging || $criteria->title !== null) {
            return false;
        }

        return $criteria->ownerScope !== null || ($this->users->mapsById() && $this->creatorColumnIsExact());
    }

    /**
     * Whether the live `creator_id` column compares byte for byte, read on every call (no memo: worker mode, and a
     * column migrated meanwhile is seen at once):
     * - MySQL / MariaDB: a `varchar` column with a binary NO PAD collation in `information_schema` (installs that never
     *   migrated to it keep the exact path);
     * - PostgreSQL: a `varchar` or `text` column (not `char(n)`, `citext` or a domain) with a deterministic collation,
     *   and no open transaction: a failed catalog read would abort the host's transaction (25P02), so inside one the
     *   exact path is used without asking;
     * - SQLite: trusted (BINARY by default; the bundle never maps another collation). A column altered by hand to
     *   NOCASE / RTRIM is not seen: the page re-check still falls back, but `count()` and a full page's total would
     *   include the look-alike rows;
     * - SQL Server (binary collations ignore trailing spaces), Oracle and any other platform: never.
     *
     * Any error while asking (missing table, no catalog access) answers no: the exact path.
     */
    private function creatorColumnIsExact(): bool
    {
        try {
            $connection = $this->entityManager->getConnection();
            $platform   = $connection->getDatabasePlatform();
            $metadata   = $this->entityManager->getClassMetadata(VaultItem::class);
            $where      = [$metadata->getTableName(), $metadata->getColumnName('creator')];

            return match (true) {
                $platform instanceof SQLitePlatform        => true,
                $platform instanceof AbstractMySQLPlatform => in_array($connection->fetchOne(self::MYSQL_COLLATION_SQL, $where), [ExactCollation::MYSQL_NOPAD, ExactCollation::MARIADB_NOPAD], true),
                $platform instanceof PostgreSQLPlatform    => !$connection->isTransactionActive()
                    && in_array($connection->fetchOne(self::POSTGRESQL_DETERMINISTIC_SQL, $where), [true, 't', 1, '1'], true),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One page read with SQL LIMIT/OFFSET (audit YF-D1), or null when a row fails the PHP re-check (the owner byte for
     * byte, each id once): the caller then takes the exact path. The total is SQL COUNT, skipped on a short last page.
     */
    private function sqlPage(VaultItemCriteria $criteria): ?VaultItemPage
    {
        $owner = $criteria->ownerScope ?? $criteria->ownerUserId;
        $qb    = $this->filtered($criteria)->select('i.id AS id', $this->ownerExpression($criteria) . ' AS owner');
        $this->order($qb, $criteria->sort);
        $this->window($qb, $criteria);
        $rows = $qb->getQuery()->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if (isset($ids[$id]) || (string) $row['owner'] !== $owner) {
                return null;
            }
            $ids[$id] = true;
        }

        $total = count($rows) < $criteria->limit && ($rows !== [] || $criteria->offset === 0)
            ? $criteria->offset + count($rows)
            : $this->sqlCount($criteria);

        return new VaultItemPage($this->findByIds(array_keys($ids)), $total, $criteria->offset, $criteria->limit);
    }

    /**
     * Restricts a fresh query builder to the criteria's page in SQL (LIMIT / OFFSET).
     */
    private function window(QueryBuilder $qb, VaultItemCriteria $criteria): void
    {
        $qb->setFirstResult($criteria->offset);
        $qb->setMaxResults($criteria->limit);
    }

    /**
     * The total with SQL COUNT (audit YF-D1; distinct ids: the tag filter joins).
     */
    private function sqlCount(VaultItemCriteria $criteria): int
    {
        return (int) $this->filtered($criteria)->select('COUNT(DISTINCT i.id)')->getQuery()->getSingleScalarResult();
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

        $qb = $this->filtered($criteria)->select('i.id AS id', 'i.title AS title', $this->ownerExpression($criteria) . ' AS owner');
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
