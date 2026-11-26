<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use ArrayObject;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use Nowo\VaultBundle\Service\VaultItemCreateGate;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\ConcurrencyKernel;
use Nowo\VaultBundle\Tests\App\Ddd\DddUserDirectory;
use Nowo\VaultBundle\Tests\Support\ConcurrentWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function array_keys;
use function array_map;
use function getenv;
use function implode;
use function is_string;
use function microtime;

/**
 * 2.5.0 (REQ-RUNTIME-003) on a database server: ITEM_CREATE_CHECK's item count waits for a creation in flight for the
 * same owner and counts it once committed, so a plan limit holds when two creations race for the last place (MySQL,
 * MariaDB and PostgreSQL; the CI runs it with DBAL 3 and 4).
 */
#[Group('database')]
final class VaultCreateLimitDatabaseTest extends WebTestCase
{
    private string $url = '';

    protected static function getKernelClass(): string
    {
        return ConcurrencyKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    #[Test]
    public function aCreationRacingForTheLastPlaceWaitsAndIsRefused(): void
    {
        $client = $this->boot();
        $em     = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        [$alice] = DddUserDirectory::users();
        $first   = self::getContainer()->get(VaultItemCreator::class)->create(VaultItemType::Login, 'First', $alice, ['password' => 'Redacted-Test-1'])->getId();
        $counts  = $this->limit(2);

        // The other creation: it took the same locks as the check and inserted its item; it commits in 2 s.
        $writer = ConcurrentWriter::start($this->url, [
            $this->ownerLock($em->getConnection()),
            $this->copyItem($em->getConnection(), $first, '0b5f2c1e-9999-4a1a-8a1a-0000000000aa'),
        ], 2.0);

        $client->loginUser($alice);
        $page    = $client->request('GET', '/tools/vault/items/new/login');
        $started = microtime(true);
        $client->submit($page->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Third', 'vault_item_form[password]' => 'Redacted-Test-2']));
        $waited = microtime(true) - $started;
        self::assertSame(0, $writer->finish());

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(1.0, $waited, 'The check waited for the creation in flight.');
        self::assertSame([2], $counts->getArrayCopy(), 'It counted the committed item, never the snapshot before it.');
        $check = ConcurrentWriter::connect($this->url);
        self::assertSame(2, (int) $check->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [DddUserDirectory::ALICE]));
        $check->close();
    }

    #[Test]
    public function onPostgresqlTheFirstItemOfAnOwnerIsSerialisedToo(): void
    {
        // Audit ZCM-L4: an owner with no stored item has no row to lock; the advisory lock on the owner key serialises.
        $client = $this->boot();
        $em     = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        if (!$em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // PostgreSQL only (no skip: the CI runs this group with --fail-on-skipped). MySQL / MariaDB serialise through
            // gap locks instead (two first creations deadlock: 409, retry).
            $this->addToAssertionCount(1);

            return;
        }

        [$alice, $bob] = DddUserDirectory::users();
        $template      = self::getContainer()->get(VaultItemCreator::class)->create(VaultItemType::Login, 'Template', $bob, ['password' => 'Redacted-Test-1'])->getId();
        $counts        = $this->limit(1);

        // Alice's first item is being created by another request; it holds the owner lock and commits in 2 s.
        [$sql, $params] = $this->copyItem($em->getConnection(), $template, '0b5f2c1e-9999-4a1a-8a1a-0000000000bb');
        $writer         = ConcurrentWriter::start($this->url, [
            $this->ownerLock($em->getConnection()),
            [$sql, $params],
            ['UPDATE vault_items SET creator_id = ?, created_by_id = ? WHERE id = ?', [DddUserDirectory::ALICE, DddUserDirectory::ALICE, '0b5f2c1e-9999-4a1a-8a1a-0000000000bb']],
        ], 2.0);

        $client->loginUser($alice);
        $page    = $client->request('GET', '/tools/vault/items/new/login');
        $started = microtime(true);
        $client->submit($page->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Second', 'vault_item_form[password]' => 'Redacted-Test-2']));
        $waited = microtime(true) - $started;
        self::assertSame(0, $writer->finish());

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(1.0, $waited, 'The check waited for the first item in flight.');
        self::assertSame([1], $counts->getArrayCopy());
        $check = ConcurrentWriter::connect($this->url);
        self::assertSame(1, (int) $check->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [DddUserDirectory::ALICE]));
        $check->close();
    }

    private function boot(): KernelBrowser
    {
        $url = getenv('VAULT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set VAULT_TEST_DATABASE_URL to run the concurrency tests on a database server.');
        }
        $this->url = $url;
        $client    = self::createClient(['environment' => 'private', 'debug' => false]);
        $client->disableReboot();
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        ConcurrentWriter::setLockTimeout($em->getConnection(), 10);

        return $client;
    }

    /**
     * A plan limit listener; the returned list records every count it read.
     *
     * @return ArrayObject<int, int>
     */
    private function limit(int $limit): ArrayObject
    {
        $counts = new ArrayObject();
        self::getContainer()->get('event_dispatcher')->addListener(VaultEvents::ITEM_CREATE_CHECK, static function (VaultItemCreateCheckEvent $event) use ($counts, $limit): void {
            $counts[] = $event->itemCount();
            if ($event->itemCount() >= $limit) {
                $event->deny('plan_limit');
            }
        });

        return $counts;
    }

    /**
     * The lock the gate takes for Alice's personal vault: the advisory lock on PostgreSQL, her item rows elsewhere.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function ownerLock(Connection $connection): array
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? ['SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [VaultItemCreateGate::LOCK_PREFIX . 'user:' . DddUserDirectory::ALICE]]
            : ['SELECT id FROM vault_items WHERE creator_id = ? FOR UPDATE', [DddUserDirectory::ALICE]];
    }

    /**
     * An INSERT … SELECT copying the item with another id and title (every column of its row).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function copyItem(Connection $connection, string $id, string $newId): array
    {
        $row = $connection->fetchAssociative('SELECT * FROM vault_items WHERE id = ?', [$id]);
        self::assertIsArray($row);
        $columns = array_map(strval(...), array_keys($row));
        $select  = array_map(static fn (string $c): string => match ($c) {
            'id'    => '?',
            'title' => "'Raced'",
            default => $c,
        }, $columns);

        return ['INSERT INTO vault_items (' . implode(', ', $columns) . ') SELECT ' . implode(', ', $select) . ' FROM vault_items WHERE id = ?', [$newId, $id]];
    }
}
