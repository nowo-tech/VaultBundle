<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultBrowserExtensionAuthEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Service\VaultFieldRevealer;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Ddd\DddUserDirectory;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use Nowo\VaultBundle\Tests\App\IdMappingKernel;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;
use function preg_replace;
use function sort;

/**
 * Strict DDD host (1.17.0, `nowo_vault.user_mapping: id`): plain-PHP security users with UUID ids, no host entity in
 * Doctrine, the user locator port aliased by the host. The vault stores user ids in string columns (no relation, no
 * foreign key) and works end to end: pages, folders, tags, grants, export, erasure, browser extension tokens; a user
 * of another identity domain with a colliding id is refused.
 */
final class VaultIdMappingEndToEndTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return IdMappingKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testSchemaHasUserIdColumnsAndNoRelationToHostUsers(): void
    {
        $this->bootClient();
        $schema = $this->em()->getConnection()->createSchemaManager();

        // Foreign keys left: the item's folder and the folder's parent (vault tables only), none to host users.
        $tables = [
            'vault_items'            => [['creator_id', 'created_by_id'], 1],
            'vault_folders'          => [['creator_id', 'created_by_id'], 1],
            'vault_tags'             => [['creator_id'], 0],
            'vault_grants'           => [['created_by_id'], 0],
            'vault_extension_tokens' => [['user_id'], 0],
        ];
        foreach ($tables as $table => [$columns, $foreignKeys]) {
            $details = $schema->introspectTable($table);
            foreach ($columns as $column) {
                self::assertSame(128, $details->getColumn($column)->getLength(), $table . '.' . $column);
            }
            self::assertCount($foreignKeys, $details->getForeignKeys(), $table);
        }
        self::assertFalse($this->em()->getMetadataFactory()->hasMetadataFor(DddUser::class));
        self::assertTrue($schema->introspectTable('vault_extension_tokens')->getColumn('user_id')->getNotnull());
        self::assertFalse($schema->introspectTable('vault_items')->getColumn('creator_id')->getNotnull());
    }

    public function testPersonalVaultThroughThePagesWithPlainSecurityUsers(): void
    {
        $client        = $this->bootClient();
        [$alice, $bob] = DddUserDirectory::users();

        $client->loginUser($alice);
        $crawler = $client->request('GET', '/tools/vault/items/new/login');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]'    => 'Mailbox-7f3a',
            'vault_item_form[username]' => 'alice',
            'vault_item_form[password]' => 'REDACTED-TEST',
        ]));
        self::assertResponseRedirects();

        $this->em()->clear();
        $items = $this->em()->getRepository(VaultItem::class)->findAll();
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame(DddUserDirectory::ALICE, $item->getCreatorId());
        self::assertSame(DddUserDirectory::ALICE, $item->getCreatedById());
        self::assertInstanceOf(VaultUserId::class, $item->getCreator());
        self::assertInstanceOf(VaultUserId::class, $item->getCreatedBy());
        self::assertTrue($item->getOwner()->isUser($alice));
        self::assertSame('user:' . DddUserDirectory::ALICE, $item->getOwner()->key());

        $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mailbox-7f3a', (string) $client->getResponse()->getContent());
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseIsSuccessful();

        $client->loginUser($bob);
        $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Mailbox-7f3a', (string) $client->getResponse()->getContent());
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertTrue($client->getResponse()->isClientError());
    }

    public function testUserOfAnotherIdentityDomainWithACollidingIdIsRefused(): void
    {
        $client = $this->bootClient();
        $alice  = DddUserDirectory::users()[0];
        $item   = $this->creator()->create(VaultItemType::SecureNote, 'Alice note', $alice, ['note' => 'x']);

        $client->loginUser(new OtherDomainUser(DddUserDirectory::ALICE, 'admin@example.com'));
        $client->request('GET', '/tools/vault/items');
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseStatusCodeSame(403);

        $this->expectException(AccessDeniedException::class);
        $this->creator()->create(VaultItemType::SecureNote, 'Spoofed', new OtherDomainUser(DddUserDirectory::ALICE, 'admin@example.com'), ['note' => 'x']);
    }

    public function testServicesRefuseAUserOfAnotherIdentityDomainOnLoadedItems(): void
    {
        $this->bootClient();
        $alice  = DddUserDirectory::users()[0];
        $item   = $this->creator()->create(VaultItemType::Login, 'Alice bank', $alice, ['password' => 'MARKER-alice-secret']);
        $folder = $this->service(VaultFolderService::class)->create('Alice folder', $alice);
        $admin  = new OtherDomainUser(DddUserDirectory::ALICE, 'admin@example.com');
        $guard  = $this->service(VaultAccessGuard::class);

        self::assertTrue($guard->canAccessItem($alice, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($admin, $item, VaultAccessAction::View));
        self::assertFalse($guard->canAccessItem($admin, $item, VaultAccessAction::Delete));
        self::assertFalse($guard->canAccessFolder($admin, $folder, VaultAccessAction::View));
        self::assertSame([$item->getId() => null], $guard->resolvePermissionMap($admin, [$item]));
        self::assertNull($guard->resolveScopeAccess($admin, 'org-1'));
        self::assertSame([$item->getId() => false], $guard->resolveScopeViewMap($admin, 'org-1', [$item->getId() => null]));

        try {
            $this->service(VaultGrantService::class)->grantAs($admin, VaultResourceType::Item, $item->getId(), GranteeType::User, DddUserDirectory::BOB, VaultPermission::Read);
            self::fail('A foreign user must not share the item.');
        } catch (VaultGrantRefusedException|VaultAccessDeniedException) {
        }

        $this->expectException(VaultAccessDeniedException::class);
        $this->service(VaultFieldRevealer::class)->reveal($admin, $item, 'password');
    }

    public function testFoldersTagsGrantsExportAndErasureByUserId(): void
    {
        $this->bootClient();
        [$alice, $bob] = DddUserDirectory::users();

        $folder = $this->service(VaultFolderService::class)->create('Work', $alice);
        $item   = $this->creator()->create(VaultItemType::Login, 'Bank', $alice, ['password' => 'p'], $folder);
        $this->service(VaultTagService::class)->syncItemTags($item, $alice, ['prod']);
        $grant = $this->service(VaultGrantService::class)->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, DddUserDirectory::BOB, VaultPermission::Read, $alice);
        $this->service(VaultGrantService::class)->grant(VaultResourceType::Item, $this->creator()->create(VaultItemType::SecureNote, 'Bob note', $bob, ['note' => 'n'])->getId(), GranteeType::User, DddUserDirectory::ALICE, VaultPermission::Read, $bob);
        $this->em()->clear();

        self::assertSame(['Work'], array_map(static fn (VaultFolder $f): string => $f->getName(), $this->service(VaultFolderService::class)->listForCreator($alice)));
        self::assertSame(['prod'], array_map(static fn (VaultTag $t): string => $t->getName(), $this->service(VaultTagService::class)->listForCreator($alice)));
        $stored = $this->em()->find(VaultGrant::class, $grant->getId());
        self::assertInstanceOf(VaultGrant::class, $stored);
        self::assertSame(DddUserDirectory::ALICE, $stored->getCreatedById());
        self::assertSame(1, $this->service(VaultPersonalExportStreamer::class)->measure($alice)['count']);

        $exporter = self::getContainer()->get('test.personal_data_exporter');
        self::assertInstanceOf(VaultPersonalDataExporter::class, $exporter);
        $export = $exporter->export(DddUserDirectory::ALICE);
        self::assertSame(['Bank'], array_map(static fn (array $row): mixed => $row['title'], $export['items']));
        self::assertCount(1, $export['folders']);
        self::assertCount(1, $export['tags']);
        self::assertCount(1, $export['grants_given']);
        self::assertCount(1, $export['grants_received']);

        $eraser = self::getContainer()->get('test.personal_data_eraser');
        self::assertInstanceOf(VaultPersonalDataEraser::class, $eraser);
        $result = $eraser->erase(DddUserDirectory::ALICE)->toArray();
        self::assertSame(1, $result['items']);
        self::assertSame(1, $result['folders']);
        self::assertSame(1, $result['tags']);
        $this->em()->clear();
        $titles = array_map(static fn (VaultItem $i): string => $i->getTitle(), $this->em()->getRepository(VaultItem::class)->findAll());
        sort($titles);
        self::assertSame(['Bob note'], $titles);
        self::assertSame(0, $eraser->erase(DddUserDirectory::ALICE)->toArray()['items'], 'Idempotent.');
    }

    public function testBrowserExtensionTokensResolveTheUserThroughTheLocatorPort(): void
    {
        $this->bootClient();
        $alice      = DddUserDirectory::users()[0];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $signedIn = $alice;
        $dispatcher->addListener(VaultEvents::BROWSER_EXTENSION_AUTH, static function (VaultBrowserExtensionAuthEvent $event) use (&$signedIn): void {
            $event->setResult(VaultBrowserExtensionAuthResult::success($signedIn));
        });
        $auth = self::getContainer()->get('test.extension_auth');
        self::assertInstanceOf(VaultBrowserExtensionAuthService::class, $auth);

        $issued = $auth->login('alice@example.com', 'irrelevant');
        self::assertNotNull($issued);
        $this->em()->clear();
        $token = $this->em()->getRepository(VaultExtensionToken::class)->findAll()[0];
        self::assertSame(DddUserDirectory::ALICE, $token->getUserId());
        self::assertInstanceOf(VaultUserId::class, $token->getUser());

        $resolved = $auth->resolveUser($issued['token']);
        self::assertInstanceOf(DddUser::class, $resolved);
        self::assertSame(DddUserDirectory::ALICE, $resolved->getId());

        $signedIn = new OtherDomainUser(DddUserDirectory::ALICE, 'admin@example.com');
        self::assertNull($auth->login('admin@example.com', 'irrelevant'), 'Another identity domain never gets a token.');

        self::assertSame(1, $auth->revokeAllFor($alice));
        self::assertNull($auth->resolveUser($issued['token']));
    }

    public function testGdprExportKeepsOnlyExactIdsUnderACaseInsensitiveColumn(): void
    {
        // LQ-V-M1: a host whose token column folds case (SQLite NOCASE here, a *_ci collation on MySQL): SQL returns both
        // users' tokens, the export and the token list keep only the exact id.
        $this->bootClient();
        $connection = $this->em()->getConnection();
        $create     = $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'vault_extension_tokens'");
        self::assertIsString($create);
        $connection->executeStatement('ALTER TABLE vault_extension_tokens RENAME TO vault_extension_tokens_old');
        $connection->executeStatement((string) preg_replace('/\buser_id VARCHAR\(128\) NOT NULL/', 'user_id VARCHAR(128) NOT NULL COLLATE NOCASE', $create, 1));
        $connection->executeStatement('DROP TABLE vault_extension_tokens_old');

        $upper  = new DddUser('UserA', 'upper@example.com');
        $lower  = new DddUser('usera', 'lower@example.com');
        $tokens = $this->service(VaultExtensionTokenRepositoryInterface::class);
        $tokens->save(new VaultExtensionToken('upper-hash', new DateTimeImmutable('+1 hour'), $upper));
        $tokens->save(new VaultExtensionToken('lower-hash', new DateTimeImmutable('+1 hour'), $lower));
        $this->em()->clear();
        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM vault_extension_tokens WHERE user_id = 'usera'"), 'The column folds case.');

        self::assertSame(['lower-hash'], array_map(static fn (VaultExtensionToken $t): string => $t->getTokenHash(), $tokens->findByUser($lower)));
        $exporter = self::getContainer()->get('test.personal_data_exporter');
        self::assertInstanceOf(VaultPersonalDataExporter::class, $exporter);
        self::assertCount(1, $exporter->export('usera')['extension_tokens']);
        self::assertCount(1, $exporter->export('UserA')['extension_tokens']);
    }

    private function bootClient(): KernelBrowser
    {
        $client = self::createClient(['disableReboot' => true]);
        $em     = $this->em();
        $tool   = new SchemaTool($em);
        $meta   = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function creator(): VaultItemCreator
    {
        return $this->service(VaultItemCreator::class);
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
}
