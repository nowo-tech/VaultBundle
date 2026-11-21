<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Enum\VaultItemSort;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultTrashFilter;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Service\VaultItemQuery;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\View\VaultItemPage;
use Nowo\VaultBundle\View\VaultItemView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function assert;
use function sprintf;
use function str_replace;
use function strtoupper;

/**
 * 2.1.1 (audit YF-D1): the query port pages and counts in SQL only where SQL already compares the owner byte for byte
 * (a scope, or the id mapping on an exact `creator_id` column) and there is no exact title. Both paths return the same
 * items, pages and totals for every criteria, with look-alike owner ids (case, accent, trailing space) stored next to
 * the owner's rows; a column that does not compare exactly keeps every call on the exact path.
 */
#[Group('database')]
final class ItemQuerySqlPagingKernelTest extends KernelTestCase
{
    use HostPortsSupport;

    private const PAYLOAD = ['username' => 'u', 'password' => 'REDACTED-TEST'];

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function variants(): iterable
    {
        yield 'id mapping' => ['private_id_new', true];
        yield 'relation mapping' => ['private_relation_new', false];
    }

    #[Test]
    #[DataProvider('variants')]
    public function bothPathsReturnTheSameItemsPagesAndTotals(string $variant, bool $idMapping): void
    {
        $this->bootVariant($variant);
        $alice  = $this->ref('alice');
        $folder = $this->folders()->create($alice, 'F');
        $mine   = [];
        for ($n = 0; $n < 7; ++$n) {
            $mine[] = $this->items()->create($alice, $n % 2 === 0 ? VaultItemType::Login : VaultItemType::SecureNote, 'Item ' . ($n % 3), self::PAYLOAD, $n % 2 === 0 ? $folder->id : null, $n % 3 === 0 ? ['red'] : []);
        }
        $this->items()->moveToTrash($alice, $mine[5]->id);
        $this->items()->moveToTrash($alice, $mine[6]->id);
        $this->items()->create($this->ref('bob'), VaultItemType::Login, 'Item 1', self::PAYLOAD);
        for ($n = 0; $n < 3; ++$n) {
            $this->items()->createInScope($alice, 'org-1', VaultItemType::Login, 'Item ' . $n, self::PAYLOAD);
        }
        $lookAlikes = $idMapping ? $this->storeLookAlikes() : 0;

        $tags = $this->container->get(VaultTagRepositoryInterface::class);
        assert($tags instanceof VaultTagRepositoryInterface);
        $red = $tags->findOneByCreatorAndName($this->users['alice'], 'red');
        self::assertNotNull($red);

        $sql   = $this->query();
        $exact = $this->exactQuery();
        $user  = VaultItemCriteria::forUser($this->id('alice'));
        $scope = VaultItemCriteria::forScope('org-1');
        self::assertSame($idMapping, $this->pagesInSql($sql, $user), 'id mapping on an exact column (relation mapping: never)');
        self::assertTrue($this->pagesInSql($sql, $scope));
        self::assertFalse($this->pagesInSql($sql, $user->withTitle('Item 1')), 'an exact title keeps the exact path');
        self::assertFalse($this->pagesInSql($sql, $scope->withTitle('Item 1')));
        self::assertFalse($this->pagesInSql($exact, $scope));

        $checked = 0;
        foreach (['user' => $user, 'scope' => $scope] as $owner => $base) {
            $filters = [
                'all'            => $base,
                'type'           => $base->withType(VaultItemType::SecureNote),
                'folder'         => $base->inFolder($folder->id),
                'without folder' => $base->withoutFolder(),
                'tag'            => $base->withTag($red->getId()),
                'contains'       => $base->withTitleContaining('ITEM'),
                'trashed'        => $base->withTrash(VaultTrashFilter::Trashed),
                'any'            => $base->withTrash(VaultTrashFilter::Any),
                'title'          => $base->withTitle('Item 1'),
                'unknown owner'  => $owner === 'user' ? VaultItemCriteria::forUser('7d1e-nobody') : VaultItemCriteria::forScope('org-2'),
            ];
            foreach ($filters as $label => $criteria) {
                foreach (VaultItemSort::cases() as $sort) {
                    foreach ([[0, 2], [2, 2], [4, 2], [6, 2], [0, 3], [3, 3], [0, 500], [9, 2]] as [$offset, $limit]) {
                        $case = sprintf('%s %s %s %d/%d', $owner, $label, $sort->name, $offset, $limit);
                        $c    = $criteria->sortedBy($sort)->page($offset, $limit);
                        self::assertSame($this->summary($exact->find($c)), $this->summary($sql->find($c)), $case);
                        self::assertSame($exact->count($c), $sql->count($c), $case);
                        ++$checked;
                    }
                }
            }
        }
        self::assertSame(640, $checked);

        // The look-alike rows are stored, and never listed or counted as alice's.
        self::assertSame($idMapping ? 3 : 0, $lookAlikes);
        self::assertSame(7, $sql->count($user->withTrash(VaultTrashFilter::Any)));
        self::assertSame(5, $sql->find($user->page(0, 500))->total);
        self::assertSame($this->ids($exact->find($user->withTrash(VaultTrashFilter::Any)->page(0, 500))), $this->ids($sql->find($user->withTrash(VaultTrashFilter::Any)->page(0, 500))));
        foreach ($sql->find($user->withTrash(VaultTrashFilter::Any)->page(0, 500))->items as $item) {
            self::assertSame($this->id('alice'), $item->ownerUserId);
        }
        self::assertSame(3, $sql->count($scope));
    }

    /**
     * A `creator_id` column that does not compare byte for byte (a hand-made change: a case-insensitive collation)
     * never thins or pads a page: MySQL / MariaDB / PostgreSQL see the collation and keep the exact path; SQLite is
     * trusted to compare exactly (its default, which the bundle never changes), so its page re-check finds the
     * look-alike rows and answers through the exact path.
     */
    #[Test]
    public function aColumnThatDoesNotCompareExactlyKeepsTheExactResults(): void
    {
        $this->bootVariant('private_id_new');
        $alice = $this->ref('alice');
        for ($n = 0; $n < 3; ++$n) {
            $this->items()->create($alice, VaultItemType::Login, 'Mine ' . $n, self::PAYLOAD);
        }
        $this->storeLookAlikes();

        $connection = $this->vaultManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        match (true) {
            $platform instanceof AbstractMySQLPlatform => $connection->executeStatement('ALTER TABLE vault_items MODIFY creator_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL'),
            $platform instanceof PostgreSQLPlatform    => $this->caseInsensitivePostgreSqlColumn(),
            default                                    => $this->caseInsensitiveSqliteColumn(),
        };
        $this->vaultManager()->clear();

        $upper = strtoupper($this->id('alice'));
        self::assertGreaterThan(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [$upper]), 'the column now matches look-alikes');

        $sql   = $this->query();
        $exact = $this->exactQuery();
        $user  = VaultItemCriteria::forUser($this->id('alice'));
        self::assertSame($platform instanceof SQLitePlatform, $this->pagesInSql($sql, $user));
        foreach (VaultItemSort::cases() as $sort) {
            $c    = $user->sortedBy($sort)->page(0, 500);
            $page = $sql->find($c);
            self::assertSame($this->summary($exact->find($c)), $this->summary($page), $sort->name);
            self::assertSame(3, $page->total);
            foreach ($page->items as $item) {
                self::assertSame($this->id('alice'), $item->ownerUserId);
            }
        }
        if (!$platform instanceof SQLitePlatform) {
            self::assertSame(3, $sql->count($user));
            self::assertSame($this->summary($exact->find($user->page(1, 1))), $this->summary($sql->find($user->page(1, 1))));
        }
    }

    /**
     * Audit YL-L1/L2: a fixed-width column (`char(n)`, a hand-made change) keeps the exact path on MySQL / MariaDB /
     * PostgreSQL, and PostgreSQL is not asked inside an open transaction (a failed catalog read would abort it).
     */
    #[Test]
    public function aFixedWidthColumnOrAPostgreSqlTransactionKeepsTheExactPath(): void
    {
        $this->bootVariant('private_id_new');
        $alice = $this->ref('alice');
        for ($n = 0; $n < 3; ++$n) {
            $this->items()->create($alice, VaultItemType::Login, 'Mine ' . $n, self::PAYLOAD);
        }

        $connection = $this->vaultManager()->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $sql        = $this->query();
        $user       = VaultItemCriteria::forUser($this->id('alice'));
        self::assertTrue($this->pagesInSql($sql, $user), 'the mapped column is exact');

        $connection->beginTransaction();
        try {
            self::assertSame(!$platform instanceof PostgreSQLPlatform, $this->pagesInSql($sql, $user), 'inside a transaction');
            self::assertSame(3, $sql->count($user));
        } finally {
            $connection->rollBack();
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            $collation = $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vault_items' AND COLUMN_NAME = 'creator_id'");
            $connection->executeStatement('ALTER TABLE vault_items MODIFY creator_id CHAR(128) CHARACTER SET utf8mb4 COLLATE ' . $collation . ' DEFAULT NULL');
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $connection->executeStatement('ALTER TABLE vault_items ALTER COLUMN creator_id TYPE CHAR(128)');
        }
        $this->vaultManager()->clear();

        self::assertSame($platform instanceof SQLitePlatform, $this->pagesInSql($sql, $user), 'char(n): exact path (SQLite has no fixed width)');
        foreach (VaultItemSort::cases() as $sort) {
            $c = $user->sortedBy($sort)->page(0, 2);
            self::assertSame($this->summary($this->exactQuery()->find($c)), $this->summary($sql->find($c)), $sort->name);
        }
    }

    /**
     * Three items of bob's moved onto look-alikes of alice's id: upper case, an accent, a trailing space.
     */
    private function storeLookAlikes(): int
    {
        $alice      = $this->id('alice');
        $connection = $this->vaultManager()->getConnection();
        $stored     = 0;
        foreach ([strtoupper($alice), str_replace('e', 'é', $alice), $alice . ' '] as $n => $lookAlike) {
            $item = $this->items()->create($this->ref('bob'), VaultItemType::Login, 'Look-alike ' . $n, self::PAYLOAD);
            $stored += (int) $connection->executeStatement('UPDATE vault_items SET creator_id = ? WHERE id = ?', [$lookAlike, $item->id]);
        }
        $this->vaultManager()->clear();

        return $stored;
    }

    private function caseInsensitivePostgreSqlColumn(): int
    {
        $connection = $this->vaultManager()->getConnection();
        $connection->executeStatement("CREATE COLLATION IF NOT EXISTS vault_test_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");

        return (int) $connection->executeStatement('ALTER TABLE vault_items ALTER COLUMN creator_id TYPE VARCHAR(255) COLLATE vault_test_ci');
    }

    /**
     * SQLite cannot change a column's collation: a NOCASE copy of the column replaces it (its index dropped first).
     */
    private function caseInsensitiveSqliteColumn(): int
    {
        $connection = $this->vaultManager()->getConnection();
        foreach ($connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'vault_items' AND sql LIKE '%creator_id%'") as $index) {
            $connection->executeStatement('DROP INDEX "' . $index . '"');
        }
        $connection->executeStatement('ALTER TABLE vault_items ADD COLUMN creator_nocase VARCHAR(255) COLLATE NOCASE DEFAULT NULL');
        $connection->executeStatement('UPDATE vault_items SET creator_nocase = creator_id');
        $connection->executeStatement('ALTER TABLE vault_items DROP COLUMN creator_id');

        return (int) $connection->executeStatement('ALTER TABLE vault_items RENAME COLUMN creator_nocase TO creator_id');
    }

    private function vaultManager(): VaultEntityManager
    {
        $manager = $this->container->get(VaultEntityManager::class);
        assert($manager instanceof VaultEntityManager);

        return $manager;
    }

    /**
     * The same port forced onto the exact path (one row per candidate, paged in PHP).
     */
    private function exactQuery(): VaultItemQuery
    {
        $users = $this->container->get(VaultUserReferenceResolverInterface::class);
        assert($users instanceof VaultUserReferenceResolverInterface);

        return new VaultItemQuery($this->vaultManager(), $users, false);
    }

    private function pagesInSql(object $query, VaultItemCriteria $criteria): bool
    {
        return (bool) (new ReflectionMethod(VaultItemQuery::class, 'exactInSql'))->invoke($query, $criteria);
    }

    /**
     * @return array{ids: list<string>, total: int, offset: int, limit: int}
     */
    private function summary(VaultItemPage $page): array
    {
        return ['ids' => $this->ids($page), 'total' => $page->total, 'offset' => $page->offset, 'limit' => $page->limit];
    }

    /**
     * @return list<string>
     */
    private function ids(VaultItemPage $page): array
    {
        return array_map(static fn (VaultItemView $view): string => $view->id, $page->items);
    }
}
