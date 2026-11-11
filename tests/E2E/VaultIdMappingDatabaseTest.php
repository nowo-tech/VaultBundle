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
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantResult;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\IdMappingKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Throwable;

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
final class VaultIdMappingDatabaseTest extends WebTestCase
{
    private KernelBrowser $client;

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

        $this->client = self::createClient(['environment' => 'database', 'debug' => false]);
        $this->client->disableReboot();
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
            $collations = $connection->fetchAllKeyValue("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME), COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'vault\\_%' AND COLUMN_NAME IN ('creator_id', 'created_by_id', 'user_id', 'grantee_id')");
            self::assertGreaterThanOrEqual(8, count($collations));
            self::assertArrayHasKey('vault_grants.grantee_id', $collations);
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

    public function testGrantsMatchTheExactGranteeOnly(): void
    {
        $this->assertGrantsExact(false);
    }

    public function testGrantsMatchTheExactGranteeOnlyUnderACaseInsensitiveCollation(): void
    {
        // LQ-V-P2-M1: a host whose migration dropped the binary collation on grantee_id.
        $this->foldCase($this->em()->getConnection(), 'vault_grants', 'grantee_id', true);

        $this->assertGrantsExact(true);
    }

    /**
     * An item of `owner-c` shared Read with `UserA`: the case variant `usera` and the zero-width variant `Us\u{200B}erA`
     * get nothing (guard, reveal route, shared list, grant lookup, GDPR export and erasure, upsert).
     */
    private function assertGrantsExact(bool $folded): void
    {
        $owner = new DddUser('owner-c', 'id:owner-c');
        $upper = new DddUser('UserA', 'id:UserA');
        $lower = new DddUser('usera', 'id:usera');
        $zwsp  = new DddUser("Us\u{200B}erA", "id:Us\u{200B}erA");

        $item   = $this->service(VaultItemCreator::class)->create(VaultItemType::Login, 'Shared bank', $owner, ['password' => 'MARKER-shared']);
        $grants = $this->service(VaultGrantService::class);
        self::assertSame(VaultGrantResult::Created, $grants->grantAs($owner, VaultResourceType::Item, $item->getId(), GranteeType::User, 'UserA', VaultPermission::Read));
        $this->em()->clear();
        if ($folded) {
            self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM vault_grants WHERE grantee_id = 'usera'"), 'The collation folds case.');
        }

        $guard = $this->service(VaultAccessGuard::class);
        self::assertTrue($guard->canAccessItem($upper, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($lower, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($zwsp, $item, VaultAccessAction::View), 'Invisible characters: not a vault user.');
        self::assertSame([$item->getId() => null], $guard->resolvePermissionMap($lower, [$item]));

        $shared = $this->service(VaultSharedItemResolver::class);
        self::assertSame(['Shared bank'], array_map(static fn (VaultItem $i): string => $i->getTitle(), $shared->resolve($upper)));
        self::assertSame([], $shared->resolve($lower));

        $repository = $this->service(VaultGrantRepositoryInterface::class);
        self::assertNotNull($repository->findOne(VaultResourceType::Item, $item->getId(), GranteeType::User, 'UserA'));
        self::assertNull($repository->findOne(VaultResourceType::Item, $item->getId(), GranteeType::User, 'usera'));
        self::assertSame([], $repository->findByGrantee(GranteeType::User, 'usera'));

        // Through the pages: UserA reveals, usera and the zero-width variant do not, nor see it as shared.
        self::assertSame(200, $this->reveal($upper, $item->getId()));
        self::assertStringContainsString('MARKER-shared', (string) $this->client->getResponse()->getContent());
        self::assertNotSame(200, $this->reveal($lower, $item->getId()));
        self::assertStringNotContainsString('MARKER-shared', (string) $this->client->getResponse()->getContent());
        $this->client->loginUser($lower);
        $this->client->request('GET', '/tools/vault/shared');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Shared bank', (string) $this->client->getResponse()->getContent());
        $this->client->loginUser($zwsp);
        $this->client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseStatusCodeSame(403);

        $exporter = self::getContainer()->get('test.personal_data_exporter');
        self::assertInstanceOf(VaultPersonalDataExporter::class, $exporter);
        self::assertSame([], $exporter->export('usera')['grants_received']);
        self::assertCount(1, $exporter->export('UserA')['grants_received']);

        $eraser = self::getContainer()->get('test.personal_data_eraser');
        self::assertInstanceOf(VaultPersonalDataEraser::class, $eraser);
        self::assertSame(0, $eraser->erase('usera')->toArray()['grants_received']);
        $this->em()->clear();
        self::assertNotNull($repository->findOne(VaultResourceType::Item, $item->getId(), GranteeType::User, 'UserA'), 'UserA keeps the grant.');

        // Upsert for usera never updates UserA's grant: a separate grant (binary column) or a refused insert (folded
        // unique index), UserA's Read unchanged either way.
        try {
            $result = $grants->grantAs($owner, VaultResourceType::Item, $item->getId(), GranteeType::User, 'usera', VaultPermission::Write);
            self::assertFalse($folded, 'The folded unique index refuses a second grantee row.');
            self::assertSame(VaultGrantResult::Created, $result);
        } catch (Throwable $exception) {
            self::assertTrue($folded, $exception->getMessage());
        }
        $permissions = [];
        foreach ($this->em()->getConnection()->fetchAllAssociative('SELECT grantee_id, permission FROM vault_grants') as $row) {
            $permissions[(string) $row['grantee_id']] = (string) $row['permission'];
        }
        self::assertSame(VaultPermission::Read->value, $permissions['UserA']);
        self::assertSame($folded ? null : VaultPermission::Write->value, $permissions['usera'] ?? null);
    }

    private function reveal(DddUser $user, string $itemId): int
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/tools/vault/shared');
        $request = $this->client->getRequest();
        $stack   = self::getContainer()->get('request_stack');
        $stack->push($request);
        try {
            $manager = self::getContainer()->get('security.csrf.token_manager');
            self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);
            $token = $manager->getToken('vault_reveal_' . $itemId)->getValue();
            $request->getSession()->save();
        } finally {
            $stack->pop();
        }
        $this->client->request('POST', '/tools/vault/items/' . $itemId . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $token]);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
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
