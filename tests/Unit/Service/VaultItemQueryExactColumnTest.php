<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
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
        yield 'SQL Server pads' => [new SQLServerPlatform(), null, false, null];
        yield 'SQLite' => [new SQLitePlatform(), null, true, null];
        yield 'Oracle' => [new OraclePlatform(), null, true, null];
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

        $metadata = new ClassMetadata(VaultItem::class);
        $metadata->setPrimaryTable(['name' => 'vault_items']);
        $metadata->mapField(['fieldName' => 'creator', 'columnName' => 'creator_id', 'type' => 'string']);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('getClassMetadata')->willReturn($metadata);

        $users = $this->createMock(VaultUserReferenceResolverInterface::class);
        $users->method('mapsById')->willReturn(true);

        $exactInSql = new ReflectionMethod(VaultItemQuery::class, 'exactInSql');
        self::assertSame($exact, $exactInSql->invoke(new VaultItemQuery($manager, $users), VaultItemCriteria::forUser('alice')));
    }
}
