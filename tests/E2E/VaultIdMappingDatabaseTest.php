<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Index;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\IdMappingKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function array_values;
use function count;
use function getenv;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function sort;

/**
 * LQ-V-M1 (1.17.0) on a database server (MySQL, MariaDB, PostgreSQL): with `user_mapping: id` the user id columns
 * compare byte for byte (`utf8mb4_bin` on MySQL / MariaDB), so `usera` never matches `UserA`; they are indexed; and the
 * post-filters keep every secret, GDPR and token path exact even when a host's column uses a case-insensitive collation.
 * Set VAULT_TEST_DATABASE_URL to run (CI job "Id mapping on database servers").
 */
#[Group('database')]
final class VaultIdMappingDatabaseTest extends KernelTestCase
{
    private DddUser $upper;

    private DddUser $lower;

    protected static function getKernelClass(): string
    {
        return IdMappingKernel::class;
    }

    protected function setUp(): void
    {
        $url = getenv('VAULT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set VAULT_TEST_DATABASE_URL to run the id-mapping tests on a database server.');
        }

        self::bootKernel(['environment' => 'database', 'debug' => false]);
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        $this->upper = new DddUser('UserA', 'upper@example.com');
        $this->lower = new DddUser('usera', 'lower@example.com');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testUserIdColumnsAreBinaryAndIndexed(): void
    {
        $connection = $this->em()->getConnection();
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $collations = $connection->fetchAllKeyValue("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME), COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'vault\\_%' AND COLUMN_NAME IN ('creator_id', 'created_by_id', 'user_id')");
            self::assertGreaterThanOrEqual(7, count($collations));
            foreach ($collations as $column => $collation) {
                self::assertSame('utf8mb4_bin', $collation, (string) $column);
            }
        }

        $schema = $connection->createSchemaManager();
        foreach (['vault_items' => 'creator_id', 'vault_folders' => 'creator_id', 'vault_extension_tokens' => 'user_id', 'vault_tags' => 'creator_id', 'vault_grants' => 'created_by_id'] as $table => $column) {
            $leading = array_map(static fn (Index $index): string => array_values($index->getColumns())[0] ?? '', $schema->listTableIndexes($table));
            self::assertContains($column, $leading, $table . '.' . $column);
        }
    }

    public function testCaseVariantsAreDifferentUsers(): void
    {
        $this->seed();

        $this->assertExactEverywhere(true);
    }

    public function testPostFiltersKeepEveryPathExactUnderACaseInsensitiveCollation(): void
    {
        // A host whose migration dropped the binary collation: SQL matches both users, PHP must not.
        $connection = $this->em()->getConnection();
        foreach (['vault_items' => ['creator_id', 'created_by_id'], 'vault_folders' => ['creator_id', 'created_by_id'], 'vault_tags' => ['creator_id'], 'vault_grants' => ['created_by_id'], 'vault_extension_tokens' => ['user_id']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->foldCase($connection, $table, $column, $column === 'user_id');
            }
        }
        $this->seed();
        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM vault_items WHERE creator_id = 'usera'"), 'The collation folds case.');

        // Lists rely on the binary collation; the secret, GDPR and token paths stay exact without it.
        $this->assertExactEverywhere(false);
    }

    /**
     * Gives the column a case-insensitive collation: `utf8mb4_unicode_ci` on MySQL / MariaDB, a nondeterministic ICU
     * collation on PostgreSQL.
     */
    private function foldCase(Connection $connection, string $table, string $column, bool $notNull): void
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement('ALTER TABLE ' . $table . ' MODIFY ' . $column . ' VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ' . ($notNull ? 'NOT NULL' : 'NULL'));

            return;
        }

        self::assertInstanceOf(PostgreSQLPlatform::class, $platform, 'Run this group on MySQL, MariaDB or PostgreSQL.');
        $connection->executeStatement("CREATE COLLATION IF NOT EXISTS vault_test_ci (provider = icu, locale = 'und-u-ks-level2', deterministic = false)");
        $connection->executeStatement('ALTER TABLE ' . $table . ' ALTER COLUMN ' . $column . ' TYPE VARCHAR(128) COLLATE vault_test_ci');
    }

    private function seed(): void
    {
        $creator = self::getContainer()->get(VaultItemCreator::class);
        self::assertInstanceOf(VaultItemCreator::class, $creator);
        $creator->create(VaultItemType::Login, 'Upper bank', $this->upper, ['password' => 'MARKER-upper']);
        $creator->create(VaultItemType::Login, 'Lower bank', $this->lower, ['password' => 'MARKER-lower']);
        $folders = self::getContainer()->get(VaultFolderService::class);
        self::assertInstanceOf(VaultFolderService::class, $folders);
        $folders->create('Upper folder', $this->upper);
        $tags = self::getContainer()->get(VaultTagService::class);
        self::assertInstanceOf(VaultTagService::class, $tags);
        $tags->findOrCreate($this->upper, 'prod');
        $tokens = self::getContainer()->get(VaultExtensionTokenRepositoryInterface::class);
        self::assertInstanceOf(VaultExtensionTokenRepositoryInterface::class, $tokens);
        $tokens->save(new VaultExtensionToken('upper-hash', new DateTimeImmutable('+1 hour'), $this->upper));
        $tokens->save(new VaultExtensionToken('lower-hash', new DateTimeImmutable('+1 hour'), $this->lower));
        $this->em()->clear();
    }

    private function assertExactEverywhere(bool $binaryCollation): void
    {
        $tokens = self::getContainer()->get(VaultExtensionTokenRepositoryInterface::class);
        self::assertInstanceOf(VaultExtensionTokenRepositoryInterface::class, $tokens);

        if ($binaryCollation) {
            $items = self::getContainer()->get(VaultItemRepositoryInterface::class);
            self::assertInstanceOf(VaultItemRepositoryInterface::class, $items);
            self::assertSame(['Lower bank'], array_map(static fn (VaultItem $i): string => $i->getTitle(), $items->findByCreator($this->lower)));
            self::assertSame(1, $items->countByCreator($this->upper));
        }
        self::assertSame(['lower-hash'], array_map(static fn (VaultExtensionToken $t): string => $t->getTokenHash(), $tokens->findByUser($this->lower)));

        $streamer = self::getContainer()->get(VaultPersonalExportStreamer::class);
        self::assertInstanceOf(VaultPersonalExportStreamer::class, $streamer);
        ob_start();
        $streamer->stream($this->lower, 'json', false, new DateTimeImmutable(), 'usera');
        $export = (string) ob_get_clean();
        self::assertStringContainsString('MARKER-lower', $export);
        self::assertStringNotContainsString('MARKER-upper', $export);

        $exporter = self::getContainer()->get('test.personal_data_exporter');
        self::assertInstanceOf(VaultPersonalDataExporter::class, $exporter);
        $data = $exporter->export('usera');
        self::assertSame(['Lower bank'], array_map(static fn (array $row): mixed => $row['title'], $data['items']));
        self::assertSame([], $data['folders']);
        self::assertSame([], $data['tags']);
        self::assertCount(1, $data['extension_tokens']);

        $eraser = self::getContainer()->get('test.personal_data_eraser');
        self::assertInstanceOf(VaultPersonalDataEraser::class, $eraser);
        $result = $eraser->erase('usera')->toArray();
        self::assertSame(1, $result['items']);
        self::assertSame(0, $result['folders']);
        self::assertSame(0, $result['tags']);
        self::assertSame(1, $result['extension_tokens']);
        $this->em()->clear();

        $left = array_map(static fn (VaultItem $i): string => $i->getTitle(), $this->em()->getRepository(VaultItem::class)->findAll());
        sort($left);
        self::assertSame(['Upper bank'], $left);
        self::assertCount(1, $this->em()->getRepository(VaultExtensionToken::class)->findAll());
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
