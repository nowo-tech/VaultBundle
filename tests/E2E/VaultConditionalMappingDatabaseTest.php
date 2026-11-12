<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\ConditionalMappingIdKernel;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\EventSubscriber\VaultEventRecorderSubscriber;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function getenv;
use function is_string;

/**
 * NC2-1 (1.18.0): with database.conditional_mapping and the browser extension off, the token table is usually missing.
 * The eraser, the exporter and the purge must not run a failing statement inside the host's transaction: on PostgreSQL
 * that aborts the transaction and the host's COMMIT silently rolls everything back. Runs on SQLite, and on the server of
 * VAULT_TEST_DATABASE_URL (MySQL, MariaDB, PostgreSQL) in the CI job "Id mapping on database servers" (group database).
 */
#[Group('database')]
final class VaultConditionalMappingDatabaseTest extends KernelTestCase
{
    private const HOST_TABLE = 'vault_test_host_rows';

    protected static function getKernelClass(): string
    {
        return ConditionalMappingIdKernel::class;
    }

    protected function setUp(): void
    {
        $url = getenv('VAULT_TEST_DATABASE_URL');
        self::bootKernel(is_string($url) && $url !== '' ? ['environment' => 'database', 'debug' => false] : []);
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        // The token table is hidden from the schema tools: drop what another test (or an earlier run) left.
        $this->connection()->executeStatement('DROP TABLE IF EXISTS vault_extension_tokens');
        $this->connection()->executeStatement('DROP TABLE IF EXISTS ' . self::HOST_TABLE);
        $this->connection()->executeStatement('CREATE TABLE ' . self::HOST_TABLE . ' (id VARCHAR(36) NOT NULL PRIMARY KEY)');
        VaultEventRecorderSubscriber::$events = [];
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('DROP TABLE IF EXISTS ' . self::HOST_TABLE);
        $this->connection()->executeStatement('DROP TABLE IF EXISTS vault_extension_tokens');
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testEraseInsideAHostTransactionWithTheTokenTableMissingCommits(): void
    {
        self::assertTrue($this->em()->getMetadataFactory()->isTransient(VaultExtensionToken::class));
        $alice = new DddUser('UserA', 'alice@example.com');
        self::getContainer()->get(VaultItemCreator::class)->create(VaultItemType::SecureNote, 'Note', $alice, ['note' => 'x']);

        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->insert(self::HOST_TABLE, ['id' => 'host-erase']);
        $result = self::getContainer()->get('test.personal_data_eraser')->erase('UserA');
        $connection->commit();

        self::assertSame(0, $result->toArray()['extension_tokens']);
        self::assertSame(1, $result->toArray()['items']);
        self::assertSame(['host-erase'], $connection->fetchFirstColumn('SELECT id FROM ' . self::HOST_TABLE), 'The host transaction committed.');
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM vault_items'), 'The erasure committed.');
    }

    public function testExportAndPurgeInsideAHostTransactionWithTheTokenTableMissingCommit(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->insert(self::HOST_TABLE, ['id' => 'host-export']);
        self::assertSame([], self::getContainer()->get('test.personal_data_exporter')->export('UserA')['extension_tokens']);
        self::assertSame(0, self::getContainer()->get('test.extension_token_repository')->removeExpired());
        $connection->commit();

        self::assertSame(['host-export'], $connection->fetchFirstColumn('SELECT id FROM ' . self::HOST_TABLE));
    }

    public function testRowsOfAnEarlierReleaseAreErasedByteExactlyInsideATransaction(): void
    {
        $connection = $this->connection();
        // The 1.17 token columns (MySQL TIMESTAMP stops at 2038; the user id column keeps the server's default collation).
        $date = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TIMESTAMP(0) WITHOUT TIME ZONE' : 'DATETIME';
        $connection->executeStatement('CREATE TABLE vault_extension_tokens (id VARCHAR(36) NOT NULL PRIMARY KEY, user_id VARCHAR(128) NOT NULL, created_at ' . $date . ' NOT NULL, expires_at ' . $date . ' NOT NULL, last_used_at ' . $date . ' NULL)');
        foreach ([
            ['00000000-0000-4000-8000-000000000021', 'UserA', '2020-01-02 00:00:00'],
            ['00000000-0000-4000-8000-000000000022', 'UserA', '2999-01-01 00:00:00'],
            ['00000000-0000-4000-8000-000000000023', 'usera', '2999-01-01 00:00:00'],
        ] as [$id, $user, $expires]) {
            $connection->insert('vault_extension_tokens', ['id' => $id, 'user_id' => $user, 'created_at' => '2020-01-01 00:00:00', 'expires_at' => $expires]);
        }

        $connection->beginTransaction();
        $connection->insert(self::HOST_TABLE, ['id' => 'host-rows']);
        self::assertCount(2, self::getContainer()->get('test.personal_data_exporter')->export('UserA')['extension_tokens']);
        self::assertSame(1, self::getContainer()->get('test.extension_token_repository')->removeExpired());
        self::assertSame(1, self::getContainer()->get('test.personal_data_eraser')->erase('UserA')->toArray()['extension_tokens']);
        $connection->commit();

        self::assertSame(['host-rows'], $connection->fetchFirstColumn('SELECT id FROM ' . self::HOST_TABLE));
        self::assertSame(['00000000-0000-4000-8000-000000000023'], $connection->fetchFirstColumn('SELECT id FROM vault_extension_tokens'), 'The case-colliding id is untouched.');
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
