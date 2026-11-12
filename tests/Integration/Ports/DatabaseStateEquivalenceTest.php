<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\VaultBundle\Doctrine\VaultSavepoint;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemUpdater;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;
use function count;
use function get_object_vars;
use function getenv;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * 1.19.0 database state equivalence: the same sequence of vault writes (create, tag, folders, grants, update, move,
 * trash, restore, purge, revoke, GDPR export and erasure) leaves exactly the same rows with the 1.18 wiring and with the
 * private entity manager, in the relation and id mappings. Ids, timestamps and ciphertexts are normalised (random by
 * design); payloads are compared decrypted.
 *
 * Runs on SQLite in the default suite and on MySQL, MariaDB and PostgreSQL in the CI job "Id mapping on database
 * servers" (group database, VAULT_TEST_DATABASE_URL).
 */
#[Group('database')]
final class DatabaseStateEquivalenceTest extends KernelTestCase
{
    use VariantKernelSupport;

    private const TABLES = ['vault_items', 'vault_folders', 'vault_tags', 'vault_item_tag', 'vault_grants'];

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pairs(): iterable
    {
        yield 'relation mapping' => ['legacy_relation_legacy', 'private_relation_new'];
        yield 'id mapping' => ['legacy_id_legacy', 'private_id_new'];
        yield 'id mapping, XML' => ['legacy_id_legacy', 'private_idxml_new'];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function theWritesLeaveTheSameRows(string $reference, string $variant): void
    {
        $server = getenv('VAULT_TEST_DATABASE_URL');
        $suffix = is_string($server) && $server !== '' ? '_db' : '';

        [$expected, $exports] = $this->scenario($reference . $suffix);
        [$actual, $actualExports] = $this->scenario($variant . $suffix);

        self::assertCount(2, $expected['written']['vault_items']);
        self::assertCount(5, $expected['written']['vault_grants']);
        self::assertNotEmpty($expected['erased']['vault_grants']);
        self::assertSame($expected, $actual);
        self::assertSame($exports, $actualExports);
    }

    #[Test]
    public function aFailingVaultStatementNeverAbortsTheHostTransaction(): void
    {
        $server = getenv('VAULT_TEST_DATABASE_URL');
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'private_relation_new' . (is_string($server) && $server !== '' ? '_db' : ''), 'debug' => false]);
        $container = self::getContainer();
        self::resetSchema($container);
        $connection = self::hostManager($container)->getConnection();

        $connection->beginTransaction();
        $connection->insert('users', ['email' => 'before@example.com', 'password' => 'x']);
        try {
            VaultSavepoint::write($connection, static fn (): int|string => $connection->executeStatement('DELETE FROM nowo_vault_missing_table WHERE id = 1'));
            self::fail('the statement fails');
        } catch (\Doctrine\DBAL\Exception) {
        }
        // Without the savepoint, PostgreSQL refuses every statement until the end of the transaction.
        $connection->insert('users', ['email' => 'after@example.com', 'password' => 'x']);
        $connection->commit();

        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM users WHERE email IN ('before@example.com', 'after@example.com')"));
    }

    /**
     * @return array{array<string, array<string, list<string>>>, string}
     */
    private function scenario(string $variant): array
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => $variant, 'debug' => false]);
        $container = self::getContainer();
        self::resetSchema($container);
        $byId  = str_contains($variant, '_id');
        $users = self::seedUsers($container, $byId);
        $ids   = self::seedVault($container, $users);
        $id    = static fn (string $name): string => (string) UserIdResolver::getId($users[$name]);
        $alice = $users['alice'];

        $items   = $container->get(VaultItemRepositoryInterface::class);
        $updater = $container->get(VaultItemUpdater::class);
        $folders = $container->get(VaultFolderService::class);
        $trash   = $container->get(VaultTrashService::class);
        $grants  = $container->get(VaultGrantService::class);
        $grantRepository = $container->get(VaultGrantRepositoryInterface::class);
        $tags    = $container->get(VaultTagService::class);
        assert($items instanceof VaultItemRepositoryInterface && $updater instanceof VaultItemUpdater && $folders instanceof VaultFolderService && $trash instanceof VaultTrashService && $grants instanceof VaultGrantService && $grantRepository instanceof VaultGrantRepositoryInterface && $tags instanceof VaultTagService);

        $personal = $items->findById($ids['personal']);
        assert($personal instanceof VaultItem);
        $second = $folders->create('Folder-G', $alice);
        $updater->update($personal, 'Title-P2', ['username' => 'u2', 'password' => 'REDACTED-TEST-2'], $second, $alice);
        $tags->syncItemTags($personal, $alice, ['work', 'home']);
        $folders->rename($second, 'Folder-G2');

        $inFolder = $items->findById($ids['in folder']);
        assert($inFolder instanceof VaultItem);
        $trash->moveItemToTrash($inFolder, $alice);
        $trash->restoreItem($inFolder, $alice);
        $trash->moveItemToTrash($inFolder, $alice);
        $trash->purgeItem($inFolder, $alice);

        $grants->grantAs($alice, VaultResourceType::Item, $ids['personal'], GranteeType::User, $id('dave'), VaultPermission::Read, new DateTimeImmutable('+2 days'));
        $bob = $grantRepository->findOne(VaultResourceType::Item, $ids['personal'], GranteeType::User, $id('bob'));
        assert($bob instanceof VaultGrant);
        $grants->revokeAs($alice, $bob);

        $beforeErasure = $this->dump($container, $users);
        $exporter      = $container->get('test.personal_data_exporter');
        $eraser   = $container->get('test.personal_data_eraser');
        assert($exporter instanceof VaultPersonalDataExporter && $eraser instanceof VaultPersonalDataEraser);
        $export = $exporter->export($id('alice'));
        $eraser->erase($id('frank'));
        $erased = $eraser->erase($id('alice'));

        return [['written' => $beforeErasure, 'erased' => $this->dump($container, $users)], json_encode([$this->normaliseExport($export), get_object_vars($erased)], JSON_THROW_ON_ERROR)];
    }

    /**
     * Every vault table, rows normalised and sorted.
     *
     * @param array<string, \Symfony\Component\Security\Core\User\UserInterface> $users
     *
     * @return array<string, list<string>>
     */
    private function dump(ContainerInterface $container, array $users): array
    {
        $connection = self::hostManager($container)->getConnection();
        $crypto     = $container->get(VaultPayloadCryptographerInterface::class);
        assert($crypto instanceof VaultPayloadCryptographerInterface);
        $names = [];
        foreach ($users as $name => $user) {
            $names[(string) UserIdResolver::getId($user)] = 'user:' . $name;
        }
        $labels = $this->labels($connection);

        $out = [];
        foreach (self::TABLES as $table) {
            $rows = [];
            foreach ($connection->fetchAllAssociative('SELECT * FROM ' . $table) as $row) {
                ksort($row);
                foreach ($row as $column => $value) {
                    $row[$column] = match (true) {
                        $value === null                                                 => null,
                        $column === 'ciphertext'                                        => $crypto->decrypt((string) $value),
                        str_ends_with((string) $column, '_at')                          => '<time>',
                        isset($names[(string) $value]) && $column !== 'title'           => $names[(string) $value],
                        isset($labels[(string) $value])                                 => $labels[(string) $value],
                        is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) === 1 => '<unknown uuid>',
                        default                                                         => (string) $value,
                    };
                }
                $rows[] = json_encode($row, JSON_THROW_ON_ERROR);
            }
            usort($rows, static fn (string $a, string $b): int => $a <=> $b);
            $out[$table] = $rows;
        }

        return $out;
    }

    /**
     * Readable labels of the row ids (item titles, folder and tag names).
     *
     * @return array<string, string>
     */
    private function labels(Connection $connection): array
    {
        $labels = [];
        foreach (['vault_items' => 'title', 'vault_folders' => 'name', 'vault_tags' => 'name'] as $table => $column) {
            foreach ($connection->fetchAllAssociative('SELECT id, ' . $column . ' AS label FROM ' . $table) as $row) {
                $labels[(string) $row['id']] = $table . ':' . $row['label'];
            }
        }
        foreach ($connection->fetchAllAssociative('SELECT id, resource_id, grantee_type, grantee_id FROM vault_grants') as $row) {
            $labels[(string) $row['id']] = 'grant:' . ($labels[(string) $row['resource_id']] ?? '?') . '>' . $row['grantee_type'];
        }

        return $labels;
    }

    /**
     * The export without its random ids and times (counts and shapes only).
     *
     * @param array<string, mixed> $export
     *
     * @return array<string, mixed>
     */
    private function normaliseExport(array $export): array
    {
        $shape = [];
        foreach ($export as $section => $rows) {
            $shape[$section] = is_array($rows) ? count($rows) : 'scalar';
        }

        return $shape;
    }
}
