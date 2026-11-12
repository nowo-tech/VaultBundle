<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use Nowo\VaultBundle\Doctrine\VaultExtensionTokenTable;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Tests\App\BeaconAdoptionKernel;
use ReflectionProperty;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_column;

/**
 * 1.18.0 NC-1: raw access to the token table while its entity is not mapped, with the id mapping (byte-exact user id,
 * LQ-V-M1) and the relation mapping (id converted with the host user's identifier type), on an existing and a missing
 * table. The user_id column is case-insensitive here (COLLATE NOCASE), like a MySQL / SQL Server default collation.
 */
final class VaultExtensionTokenTableTest extends KernelTestCase
{
    private const TABLE = 'vault_test_tokens';

    protected static function getKernelClass(): string
    {
        return BeaconAdoptionKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection()->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testIdMappingKeepsOnlyTheByteExactUserId(): void
    {
        $this->createTable('VARCHAR(128) COLLATE NOCASE');
        $this->insert('00000000-0000-4000-8000-00000000000a', 'UserA', '2999-01-01 00:00:00', '2026-02-01 10:00:00');
        $this->insert('00000000-0000-4000-8000-00000000000b', 'usera', '2999-01-01 00:00:00');
        $this->insert('00000000-0000-4000-8000-00000000000c', 'UserA', '2020-01-01 00:00:00');
        $table = new VaultExtensionTokenTable(self::TABLE);

        $rows = $table->rowsForUser($this->em(), 'UserA', true);
        self::assertSame(['00000000-0000-4000-8000-00000000000a', '00000000-0000-4000-8000-00000000000c'], array_column($rows, 'id'), 'Same created_at: by id.');
        self::assertEquals(new DateTimeImmutable('2026-02-01 10:00:00'), $rows[0]['last_used_at']);
        self::assertNull($rows[1]['last_used_at']);

        self::assertSame(1, $table->deleteExpired($this->em(), new DateTimeImmutable('2026-11-12 00:00:00')));
        self::assertSame(1, $table->deleteForUser($this->em(), 'UserA', true, 1));
        self::assertSame(['00000000-0000-4000-8000-00000000000b'], $this->connection()->fetchFirstColumn('SELECT id FROM ' . self::TABLE), 'The other case-colliding id is untouched.');
    }

    public function testRelationMappingConvertsTheIdWithTheUserIdentifierType(): void
    {
        $this->createTable('INTEGER');
        $this->insert('00000000-0000-4000-8000-00000000000d', 7, '2999-01-01 00:00:00');
        $this->insert('00000000-0000-4000-8000-00000000000e', 8, '2999-01-01 00:00:00');
        $table = new VaultExtensionTokenTable(self::TABLE);

        self::assertSame(['00000000-0000-4000-8000-00000000000d'], array_column($table->rowsForUser($this->em(), '7', false), 'id'));
        self::assertSame(1, $table->deleteForUser($this->em(), '7', false));
        self::assertSame(0, $table->deleteForUser($this->em(), '7', false));
    }

    public function testRelationMappingDoesNotMatchNonCanonicalIdsThroughNumericCoercion(): void
    {
        // NC2-2: SQLite (like MySQL) compares an INTEGER column with '01' / '1abc' numerically; the string check refuses.
        $this->createTable('INTEGER');
        $this->insert('00000000-0000-4000-8000-000000000010', 1, '2999-01-01 00:00:00');
        $table = new VaultExtensionTokenTable(self::TABLE);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . " WHERE user_id = '01'"), 'The database alone would match.');

        self::assertSame([], $table->rowsForUser($this->em(), '01', false));
        self::assertSame([], $table->rowsForUser($this->em(), '1abc', false));
        self::assertSame(0, $table->deleteForUser($this->em(), '01', false));
        self::assertCount(1, $table->rowsForUser($this->em(), '1', false));
    }

    public function testABinaryUserIdentifierIsNotComparedAsAString(): void
    {
        $this->createTable('BLOB');
        $this->connection()->insert(self::TABLE, ['id' => '00000000-0000-4000-8000-000000000011', 'user_id' => 'bin-1', 'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2999-01-01 00:00:00'], ['user_id' => ParameterType::BINARY]);
        $item = $this->createMock(ClassMetadata::class);
        $item->method('getAssociationTargetClass')->willReturn('App\\User');
        $user = $this->createMock(ClassMetadata::class);
        $user->method('getIdentifierFieldNames')->willReturn(['id']);
        $user->method('getTypeOfField')->willReturn(Types::BINARY);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection());
        $em->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => $class === VaultItem::class ? $item : $user);

        self::assertCount(1, (new VaultExtensionTokenTable(self::TABLE))->rowsForUser($em, 'bin-1', false));
    }

    public function testTheTableNameIsValidated(): void
    {
        self::assertTrue((new VaultExtensionTokenTable('public.vault_extension_tokens'))->tableExists($this->connection()) === false);

        $this->expectException(InvalidArgumentException::class);
        new VaultExtensionTokenTable('vault_tokens; DROP TABLE x');
    }

    public function testTheExistenceProbeOfEveryPlatformNeverTouchesTheTable(): void
    {
        foreach ([
            [new PostgreSQLPlatform(), 'to_regclass', null, false],
            [new PostgreSQLPlatform(), 'to_regclass', 'vault_test_tokens', true],
            [new MySQLPlatform(), 'information_schema.tables WHERE table_schema = DATABASE()', '0', false],
            [new SQLServerPlatform(), 'OBJECT_ID', null, false],
            [new SQLServerPlatform(), 'OBJECT_ID', 1234, true],
            [new OraclePlatform(), 'user_tables', '1', true],
            [new DB2Platform(), 'information_schema.tables WHERE table_name', '0', false],
        ] as [$platform, $fragment, $answer, $exists]) {
            $connection = $this->createMock(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn($platform);
            $connection->expects(self::once())->method('fetchOne')->with(self::stringContains($fragment), [self::TABLE])->willReturn($answer);
            $connection->expects(self::never())->method('executeStatement');
            $connection->expects(self::never())->method('fetchAllAssociative');

            self::assertSame($exists, (new VaultExtensionTokenTable(self::TABLE))->tableExists($connection), $platform::class);
        }
    }

    public function testAMissingTableCountsAsEmpty(): void
    {
        $table = new VaultExtensionTokenTable(self::TABLE);

        self::assertSame([], $table->rowsForUser($this->em(), 'UserA', true));
        self::assertSame(0, $table->deleteForUser($this->em(), 'UserA', true));
        self::assertSame(0, $table->deleteExpired($this->em(), new DateTimeImmutable()));
        self::assertSame(VaultExtensionTokenTable::DEFAULT_TABLE, (new ReflectionProperty(VaultExtensionTokenTable::class, 'tableName'))->getValue(new VaultExtensionTokenTable()));
    }

    public function testAnUnresolvableUserTypeFallsBackToThePlainId(): void
    {
        $this->createTable('VARCHAR(128)');
        $this->insert('00000000-0000-4000-8000-00000000000f', '42', '2999-01-01 00:00:00');
        $table = new VaultExtensionTokenTable(self::TABLE);

        // The metadata lookup fails: the plain string id is compared.
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('getConnection')->willReturn($this->connection());
        $failing->method('getClassMetadata')->willThrowException(new RuntimeException('no metadata'));
        self::assertCount(1, $table->rowsForUser($failing, '42', false));

        // A composite identifier has no single type: the plain string id is compared.
        $item = $this->createMock(ClassMetadata::class);
        $item->method('getAssociationTargetClass')->willReturn('App\\User');
        $user = $this->createMock(ClassMetadata::class);
        $user->method('getIdentifierFieldNames')->willReturn(['a', 'b']);
        $composite = $this->createMock(EntityManagerInterface::class);
        $composite->method('getConnection')->willReturn($this->connection());
        $composite->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => $class === VaultItem::class ? $item : $user);
        self::assertCount(1, $table->rowsForUser($composite, '42', false));
    }

    private function createTable(string $userColumn): void
    {
        $this->connection()->executeStatement('CREATE TABLE ' . self::TABLE . ' (id VARCHAR(36) NOT NULL PRIMARY KEY, user_id ' . $userColumn . ' NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL)');
    }

    private function insert(string $id, int|string $user, string $expiresAt, ?string $lastUsedAt = null): void
    {
        $this->connection()->insert(self::TABLE, ['id' => $id, 'user_id' => $user, 'created_at' => '2026-01-01 00:00:00', 'expires_at' => $expiresAt, 'last_used_at' => $lastUsedAt]);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}
