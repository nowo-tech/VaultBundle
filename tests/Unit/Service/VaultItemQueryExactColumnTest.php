<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\VaultBundle\Doctrine\ExactCollation;
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Service\VaultItemQuery;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * 2.1.1 (audit YF-D1): with the id mapping, SQL pages and counts only when the live `creator_id` column compares byte
 * for byte, read from the database on every call (the DB group checks the same on real MySQL, MariaDB and PostgreSQL).
 */
final class VaultItemQueryExactColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{0: AbstractPlatform, 1: bool|int|string|null, 2: bool, 3: string|null}>
     */
    public static function columns(): iterable
    {
        yield 'MySQL NO PAD binary' => [new MySQL80Platform(), ExactCollation::MYSQL_NOPAD, true, 'information_schema'];
        yield 'MariaDB NO PAD binary' => [new MariaDBPlatform(), ExactCollation::MARIADB_NOPAD, true, 'information_schema'];
        yield 'MySQL PAD SPACE binary' => [new MySQL80Platform(), ExactCollation::MYSQL_PAD_FALLBACK, false, 'information_schema'];
        yield 'MySQL case-insensitive' => [new MySQL80Platform(), 'utf8mb4_0900_ai_ci', false, 'information_schema'];
        yield 'MySQL unknown column' => [new MySQL80Platform(), false, false, 'information_schema'];
        yield 'PostgreSQL deterministic (bool)' => [new PostgreSQLPlatform(), true, true, 'pg_collation'];
        yield 'PostgreSQL deterministic (t)' => [new PostgreSQLPlatform(), 't', true, 'pg_collation'];
        yield 'PostgreSQL nondeterministic' => [new PostgreSQLPlatform(), false, false, 'pg_collation'];
        yield 'PostgreSQL nondeterministic (f)' => [new PostgreSQLPlatform(), 'f', false, 'pg_collation'];
        yield 'PostgreSQL char(n) or citext column' => [new PostgreSQLPlatform(), false, false, 'atttypid'];
        yield 'PostgreSQL unknown column' => [new PostgreSQLPlatform(), false, false, 'pg_collation'];
        yield 'MySQL char(n) column' => [new MySQL80Platform(), false, false, "DATA_TYPE = 'varchar'"];
        yield 'SQL Server pads' => [new SQLServerPlatform(), null, false, null];
        yield 'SQLite' => [new SQLitePlatform(), null, true, null];
        yield 'Oracle: unknown here, exact path' => [new OraclePlatform(), null, false, null];
        yield 'DB2: unknown here, exact path' => [new DB2Platform(), null, false, null];
    }

    #[Test]
    #[DataProvider('columns')]
    public function sqlPagesOnlyOnAColumnThatComparesExactly(AbstractPlatform $platform, string|bool|int|null $answer, bool $exact, ?string $asked): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects($asked === null ? self::never() : self::once())
            ->method('fetchOne')
            ->with(self::stringContains((string) $asked), ['vault_items', 'creator_id'])
            ->willReturn($answer);

        self::assertSame($exact, $this->exactInSql($connection));
    }

    /**
     * Audit YL-L2: inside a transaction PostgreSQL is not asked (a failed catalog read would abort the host's
     * transaction, 25P02); the exact path is used.
     */
    #[Test]
    public function postgreSqlInsideATransactionKeepsTheExactPath(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::never())->method('fetchOne');

        self::assertFalse($this->exactInSql($connection));
    }

    /**
     * Audit YL-L2: a catalog read that fails (no access, missing table) answers the exact path, never an error.
     */
    #[Test]
    #[DataProvider('askingPlatforms')]
    public function aFailingCatalogReadKeepsTheExactPath(AbstractPlatform $platform): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->expects(self::once())->method('fetchOne')->willThrowException(new RuntimeException('permission denied for pg_attribute'));

        self::assertFalse($this->exactInSql($connection));
    }

    /**
     * @return iterable<string, array{0: AbstractPlatform}>
     */
    public static function askingPlatforms(): iterable
    {
        yield 'MySQL' => [new MySQL80Platform()];
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
    }

    private function exactInSql(Connection $connection): bool
    {
        $metadata = new ClassMetadata(VaultItem::class);
        $metadata->setPrimaryTable(['name' => 'vault_items']);
        $metadata->mapField(['fieldName' => 'creator', 'columnName' => 'creator_id', 'type' => 'string']);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $users = $this->createMock(VaultUserReferenceResolverInterface::class);
        $users->method('mapsById')->willReturn(true);

        return (bool) (new ReflectionMethod(VaultItemQuery::class, 'exactInSql'))->invoke(new VaultItemQuery($manager, $users), VaultItemCriteria::forUser('alice'));
    }
}
