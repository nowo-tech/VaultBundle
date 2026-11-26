<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\ConcurrencyKernel;
use Nowo\VaultBundle\Tests\App\Ddd\DddUserDirectory;
use Nowo\VaultBundle\Tests\Support\ConcurrentWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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
        $url = getenv('VAULT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set VAULT_TEST_DATABASE_URL to run the concurrency tests on a database server.');
        }
        $client = self::createClient(['environment' => 'private', 'debug' => false]);
        $client->disableReboot();
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        ConcurrentWriter::setLockTimeout($em->getConnection(), 10);

        [$alice] = DddUserDirectory::users();
        $first   = self::getContainer()->get(VaultItemCreator::class)->create(VaultItemType::Login, 'First', $alice, ['password' => 'Redacted-Test-1'])->getId();
        $counts  = [];
        self::getContainer()->get('event_dispatcher')->addListener(VaultEvents::ITEM_CREATE_CHECK, static function (VaultItemCreateCheckEvent $event) use (&$counts): void {
            $counts[] = $event->itemCount();
            if ($event->itemCount() >= 2) {
                $event->deny('plan_limit');
            }
        });

        // The other creation: it took the same row locks as the check and inserted its item; it commits in 2 s.
        $writer = ConcurrentWriter::start($url, [
            ['SELECT id FROM vault_items WHERE creator_id = ? FOR UPDATE', [DddUserDirectory::ALICE]],
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
        self::assertSame([2], $counts, 'It counted the committed item, never the snapshot before it.');
        $check = ConcurrentWriter::connect($url);
        self::assertSame(2, (int) $check->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [DddUserDirectory::ALICE]));
        $check->close();
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
