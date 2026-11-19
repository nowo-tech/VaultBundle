<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\EventListener\VaultConflictListener;
use Nowo\VaultBundle\Exception\VaultItemChangedException;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\ConcurrencyKernel;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Ddd\DddUserDirectory;
use Nowo\VaultBundle\Tests\App\EventSubscriber\CommitFirstSubscriber;
use Nowo\VaultBundle\Tests\Support\ConcurrentWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function array_keys;
use function array_map;
use function array_values;
use function getenv;
use function implode;
use function is_string;
use function str_replace;
use function strtoupper;

/**
 * REQ-RUNTIME-003 on a database server (MySQL 8.4, MariaDB 11.4, PostgreSQL 16; VAULT_TEST_DATABASE_URL), with two
 * connections, with the private entity manager and in the 1.18 mode:
 *
 * - two people editing one item: a page opened before the other save is refused (409, VAULT_ITEM_CHANGED), and a save
 *   that waits for the other one keeps its secrets (no lost update);
 * - a locked row answers 409 + CONFLICT_001 + Retry-After with nothing saved, CONFLICT_002 without Retry-After after a
 *   commit earlier in the request, and the connection serves the next request;
 * - a folder trash that meets a lock leaves every item in the folder (one transaction);
 * - two requests creating the same new tag: one wins, the other answers the domain 409 (VAULT_DUPLICATE_KEY) and saves
 *   nothing; saving again reuses the tag.
 *
 * Every session has a short lock timeout (a hang fails fast).
 */
#[Group('database')]
final class VaultConcurrencyDatabaseTest extends WebTestCase
{
    private KernelBrowser $client;

    private string $url;

    private DddUser $alice;

    protected static function getKernelClass(): string
    {
        return ConcurrencyKernel::class;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function modes(): iterable
    {
        yield 'private entity manager' => ['private'];
        yield '1.18 mode' => ['legacy'];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('modes')]
    public function aPageOpenedBeforeAnotherSaveIsRefusedAndTheOtherSaveIsKept(string $mode): void
    {
        $this->boot($mode);
        $id   = $this->item('Bank', 'REDACTED-FIRST');
        $page = $this->client->request('GET', '/tools/vault/items/' . $id . '/edit');
        self::assertResponseIsSuccessful();

        // The other editor saves (a new password and title) and commits while this save waits on the row lock.
        $writer = ConcurrentWriter::start($this->url, [$this->otherSave($id, 'Bank (other)', 'REDACTED-SECOND')], 1.5);
        $this->client->submit($this->itemForm($page, ['vault_item_form[title]' => 'Bank (stale)']));
        self::assertSame(0, $writer->finish());

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame(VaultConflictListener::ERROR_CODE_HEADER, VaultItemChangedException::CODE);
        self::assertFalse($this->client->getResponse()->headers->has('X-Conflict-Code'), 'a domain conflict, not a lock conflict');
        self::assertSame(['Bank (other)', 'REDACTED-SECOND'], $this->stored($id));

        // The 409 page carries the current item: saving it now works on the same connection.
        $this->client->submit($this->itemForm($this->client->getCrawler(), ['vault_item_form[title]' => 'Bank (now)']));
        self::assertResponseRedirects();
        self::assertSame(['Bank (now)', 'REDACTED-SECOND'], $this->stored($id));
    }

    #[Test]
    #[DataProvider('modes')]
    public function aSaveThatWaitsForAnotherKeepsItsSecrets(string $mode): void
    {
        $this->boot($mode);
        $id   = $this->item('Bank', 'REDACTED-FIRST');
        $page = $this->client->request('GET', '/tools/vault/items/' . $id . '/edit');

        $writer = ConcurrentWriter::start($this->url, [$this->otherSave($id, 'Bank', 'REDACTED-SECOND')], 1.5);
        // A form without token (a host form built before 1.20): the save is not refused, it waits for the other one
        // and keeps the secret that one saved (before 1.20 the stored password went back to REDACTED-FIRST).
        $this->client->submit($this->itemForm($page, ['vault_item_form[title]' => 'Bank (renamed)', 'vault_item_form[editToken]' => '']));
        self::assertSame(0, $writer->finish());

        self::assertResponseRedirects();
        self::assertSame(['Bank (renamed)', 'REDACTED-SECOND'], $this->stored($id));
    }

    #[Test]
    #[DataProvider('modes')]
    public function aLockedItemAnswers409Conflict001AndNothingIsSaved(string $mode): void
    {
        $this->boot($mode);
        $id   = $this->item('Bank', 'REDACTED-FIRST');
        $page = $this->client->request('GET', '/tools/vault/items/' . $id . '/edit');
        $this->setLockTimeout(1);

        $holder = ConcurrentWriter::hold($this->url);
        $holder->executeQuery('SELECT id FROM vault_items WHERE id = ? FOR UPDATE', [$id]);
        $this->client->submit($this->itemForm($page, ['vault_item_form[title]' => 'Bank (blocked)']));
        $holder->rollBack();

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('X-Conflict-Code', ConflictCode::RETRY_SAFE);
        self::assertResponseHeaderSame('Retry-After', '1');
        self::assertSame(['Bank', 'REDACTED-FIRST'], $this->stored($id));

        // Retrying is safe and works on the same connection.
        $this->setLockTimeout(10);
        $page = $this->client->request('GET', '/tools/vault/items/' . $id . '/edit');
        $this->client->submit($this->itemForm($page, ['vault_item_form[title]' => 'Bank (retried)']));
        self::assertResponseRedirects();
        self::assertSame(['Bank (retried)', 'REDACTED-FIRST'], $this->stored($id));
    }

    #[Test]
    #[DataProvider('modes')]
    public function aConflictAfterACommitEarlierInTheRequestAnswersConflict002(string $mode): void
    {
        $this->boot($mode);
        $id   = $this->item('Bank', 'REDACTED-FIRST');
        $page = $this->client->request('GET', '/tools/vault/items/' . $id . '/edit');
        $this->setLockTimeout(1);

        $holder = ConcurrentWriter::hold($this->url);
        $holder->executeQuery('SELECT id FROM vault_items WHERE id = ? FOR UPDATE', [$id]);
        $this->client->setServerParameter('HTTP_' . str_replace('-', '_', strtoupper(CommitFirstSubscriber::HEADER)), '1');
        $this->client->submit($this->itemForm($page, ['vault_item_form[title]' => 'Bank (blocked)']));
        $holder->rollBack();

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('X-Conflict-Code', ConflictCode::COMMITTED);
        self::assertFalse($this->client->getResponse()->headers->has('Retry-After'), 'no blind retry after a partial commit');
        self::assertSame(['Bank', 'REDACTED-FIRST'], $this->stored($id));
    }

    #[Test]
    #[DataProvider('modes')]
    public function aFolderTrashThatMeetsALockLeavesEveryItemInTheFolder(string $mode): void
    {
        $this->boot($mode);
        $folders = $this->service(VaultFolderService::class);
        $folder  = $folders->create('Work', $this->alice);
        $first   = $this->service(VaultItemCreator::class)->create(VaultItemType::Login, 'One', $this->alice, ['password' => 'REDACTED-ONE'], $folder)->getId();
        $this->em()->clear();
        $this->setLockTimeout(1);

        $holder = ConcurrentWriter::hold($this->url);
        $holder->executeQuery('SELECT id FROM vault_folders WHERE id = ? FOR UPDATE', [$folder->getId()]);
        $this->client->request('POST', '/tools/vault/folders/' . $folder->getId() . '/trash', ['_token' => $this->csrf('vault_folder_trash_' . $folder->getId())]);
        $holder->rollBack();

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('X-Conflict-Code', ConflictCode::RETRY_SAFE);
        $connection = $this->connection();
        self::assertSame($folder->getId(), $connection->fetchOne('SELECT folder_id FROM vault_items WHERE id = ?', [$first]), 'the item stayed in the folder');
        self::assertNull($connection->fetchOne('SELECT deleted_at FROM vault_folders WHERE id = ?', [$folder->getId()]));
    }

    #[Test]
    #[DataProvider('modes')]
    public function twoRequestsCreatingTheSameNewTagGiveOneTagAndTheDomain409(string $mode): void
    {
        $this->boot($mode);
        $tags = $this->service(VaultTagService::class);
        $tag  = $tags->findOrCreate($this->alice, 'template');
        $row  = $this->connection()->fetchAssociative('SELECT * FROM vault_tags WHERE id = ?', [$tag->getId()]);
        self::assertIsArray($row);
        $this->connection()->executeStatement('DELETE FROM vault_tags WHERE id = ?', [$tag->getId()]);
        $this->em()->clear();
        $row['id']   = 'c0ffee00-0000-4000-8000-000000000001';
        $row['name'] = 'shared';

        $page                              = $this->client->request('GET', '/tools/vault/items/new/login');
        $values                            = $page->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Tagged'])->getPhpValues();
        $values['vault_item_form']['tags'] = ['shared'];

        // The other request inserts the same tag and commits while this one waits on the unique key.
        $insert = 'INSERT INTO vault_tags (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_map(static fn (): string => '?', $row)) . ')';
        $writer = ConcurrentWriter::start($this->url, [[$insert, array_values($row)]], 1.5);
        $this->client->request('POST', '/tools/vault/items/new/login', $values);
        self::assertSame(0, $writer->finish());

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame(VaultConflictListener::ERROR_CODE_HEADER, VaultConflictListener::DUPLICATE_KEY);
        self::assertFalse($this->client->getResponse()->headers->has('X-Conflict-Code'));
        self::assertSame(0, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM vault_items WHERE title = 'Tagged'"), 'nothing of the refused save');

        // Saving again reuses the tag the other request created.
        $this->client->request('POST', '/tools/vault/items/new/login', $values);
        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM vault_tags WHERE name = 'shared'"));
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM vault_items WHERE title = 'Tagged'"));
    }

    private function boot(string $mode): void
    {
        $url = getenv('VAULT_TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set VAULT_TEST_DATABASE_URL to run the concurrency tests on a database server.');
        }

        $this->url    = $url;
        $this->client = self::createClient(['environment' => $mode, 'debug' => false]);
        $this->client->disableReboot();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $meta = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $this->setLockTimeout(10);

        $this->alice = DddUserDirectory::users()[0];
        $this->client->loginUser($this->alice);
    }

    private function item(string $title, string $password): string
    {
        $id = $this->service(VaultItemCreator::class)->create(VaultItemType::Login, $title, $this->alice, ['username' => 'u', 'password' => $password, 'websites' => ['https://example.com']])->getId();
        $this->em()->clear();

        return $id;
    }

    /**
     * The other editor's save: a new ciphertext and title, as VaultItemUpdater writes them.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function otherSave(string $id, string $title, string $password): array
    {
        $ciphertext = $this->service(VaultPayloadCryptographerInterface::class)->encrypt(['username' => 'u', 'password' => $password, 'websites' => ['https://example.com']]);

        return ['UPDATE vault_items SET ciphertext = ?, title = ? WHERE id = ?', [$ciphertext, $title, $id]];
    }

    /**
     * @return array{0: string, 1: string} title and stored password, read on a fresh connection
     */
    private function stored(string $id): array
    {
        $connection = ConcurrentWriter::connect($this->url);
        $row        = $connection->fetchAssociative('SELECT title, ciphertext FROM vault_items WHERE id = ?', [$id]);
        $connection->close();
        self::assertIsArray($row);
        $payload = $this->service(VaultPayloadCryptographerInterface::class)->decrypt((string) $row['ciphertext']);

        return [(string) $row['title'], (string) ($payload['password'] ?? '')];
    }

    /**
     * @param array<string, string> $values
     */
    private function itemForm(Crawler $page, array $values): Form
    {
        return $page->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form($values);
    }

    private function setLockTimeout(int $seconds): void
    {
        ConcurrentWriter::setLockTimeout($this->connection(), $seconds);
    }

    private function csrf(string $tokenId): string
    {
        $this->client->request('GET', '/tools/vault/items');
        $request = $this->client->getRequest();
        $stack   = $this->service(RequestStack::class);
        $stack->push($request);
        try {
            $value = $this->service(CsrfTokenManagerInterface::class)->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        $doctrine = $this->service(ManagerRegistry::class);
        $em       = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
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
        $service = self::getContainer()->get($id === ManagerRegistry::class ? 'doctrine' : ($id === RequestStack::class ? 'request_stack' : ($id === CsrfTokenManagerInterface::class ? 'security.csrf.token_manager' : $id)));
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
