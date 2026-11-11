<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Tests\App\BeaconAdoptionKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function in_array;

/**
 * 1.18.0 options on the BeaconAdoptionKernel: sharing.enabled false (no share UI, share and revoke routes answer 404,
 * no grant is created or removed), the host note slot, Move to trash in the row kebab menu (confirm kept) and
 * database.conditional_mapping (vault_settings / vault_extension_tokens neither mapped nor created).
 */
final class VaultBeaconAdoptionEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    protected static function getKernelClass(): string
    {
        return BeaconAdoptionKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testDisabledSharingRejectsEveryShareEndpointAndKeepsTheGrants(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $this->createUser('bob@example.com');
        $item   = $this->createItem($alice, 'Mail');
        $folder = $this->createFolder($alice, 'Work');
        // Grants created through the service keep working (only the UI and its endpoints are off).
        $grants      = self::getContainer()->get(VaultGrantService::class);
        $itemGrant   = $grants->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, 'someone', VaultPermission::Read, $this->managed($alice));
        $folderGrant = $grants->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, 'someone', VaultPermission::Read, $this->managed($alice));
        $client->loginUser($alice);

        foreach (['/tools/vault/items/' . $item->getId() . '/share', '/tools/vault/folders/' . $folder->getId() . '/share'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
            $client->request('POST', $url, ['vault_share' => ['granteeType' => 'user', 'granteeId' => 'bob', 'permission' => 'admin']]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
        }

        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/grants/' . $itemGrant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $itemGrant->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('POST', '/tools/vault/folders/' . $folder->getId() . '/grants/' . $folderGrant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $folderGrant->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $repository = self::getContainer()->get(VaultGrantRepositoryInterface::class);
        self::assertNotNull($repository->findById($itemGrant->getId()));
        self::assertNotNull($repository->findById($folderGrant->getId()));
        self::assertCount(1, $repository->findByResource(VaultResourceType::Item, $item->getId()), 'No grant was added.');

        $router = self::getContainer()->get('router')->getRouteCollection();
        foreach (['nowo_vault_item_share', 'nowo_vault_item_grant_revoke', 'nowo_vault_folder_share', 'nowo_vault_folder_grant_revoke'] as $name) {
            self::assertNull($router->get($name), $name);
        }
        self::assertNotNull($router->get('nowo_vault_shared'));
    }

    public function testPagesHideTheShareUiShowTheHostNoteAndPutTrashInTheRowMenu(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $item   = $this->createItem($alice, 'Mail');
        $folder = $this->createFolder($alice, 'Work');
        self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, 'someone', VaultPermission::Read, $this->managed($alice));
        $client->loginUser($alice);

        $home = $client->request('GET', '/tools/vault');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $home->filterXPath('//*[@data-testid="vault-host-note"]//*[@data-testid="host-share-a-secret"]'));
        self::assertCount(0, $home->filterXPath('//*[@data-testid="vault-home-shared"]'));
        self::assertCount(1, $home->filterXPath('//*[@data-testid="vault-home-items"]'));

        $items = $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertCount(1, $items->filterXPath('//*[@data-testid="vault-host-note"]//*[@data-testid="host-share-a-secret"]'));
        self::assertStringNotContainsString('/share"', $html, 'No item or folder share link.');
        self::assertStringNotContainsString('/share?', $html);
        self::assertStringNotContainsString('nowo-ui-action--share', $html);
        self::assertCount(0, $items->filterXPath('//*[contains(@class, "badge")][normalize-space(.)="Shared"]'));

        $menu = $items->filterXPath('//*[@data-testid="vault-row-actions"]//*[@data-testid="vault-row-menu"]');
        self::assertCount(1, $menu, 'One kebab menu for the one row.');
        $trash = $menu->filterXPath('descendant-or-self::*[contains(concat(" ", normalize-space(@class), " "), " nowo-ui-action--delete ")]');
        self::assertCount(1, $trash, 'Move to trash is inside the menu.');
        self::assertStringContainsString('Move to trash', $trash->text());
        $confirmId = $trash->attr('data-nowo-confirm-target');
        self::assertNotNull($confirmId, 'The trash item opens its confirm dialog.');
        $dialog = $items->filterXPath('//*[@id="' . $confirmId . '"]');
        self::assertCount(1, $dialog, 'The confirm dialog is on the page.');
        self::assertCount(1, $dialog->filterXPath('descendant-or-self::form[@action="/tools/vault/items/' . $item->getId() . '/trash"]'));
        self::assertCount(0, $items->filterXPath('//*[@data-testid="vault-row-actions"]/*[contains(@class, "nowo-ui-row-actions")]//*[contains(@class, "nowo-ui-action--delete")]'), 'No inline trash button.');
        self::assertGreaterThan(0, $items->filterXPath('//*[@data-testid="vault-row-actions"]//*[contains(@class, "nowo-ui-action--view")]')->count());

        // Folder chips keep their own trash action, without the share one.
        self::assertStringContainsString('/tools/vault/folders/' . $folder->getId() . '/trash', $html);

        // The confirmation still posts the trash form.
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/trash', ['_token' => $this->csrf($client, 'vault_trash_' . $item->getId())]);
        self::assertResponseRedirects();
        $trashed = $this->findItem($item->getId());
        self::assertInstanceOf(VaultItem::class, $trashed);
        self::assertTrue($trashed->isDeleted());
    }

    public function testConditionalMappingLeavesTheDisabledFeatureTablesOut(): void
    {
        $this->bootClient();
        $em      = $this->em();
        $factory = $em->getMetadataFactory();
        // The SQLite file outlives a failed run: start without the tables an earlier run may have left.
        $em->getConnection()->executeStatement('DROP TABLE IF EXISTS vault_settings');
        $em->getConnection()->executeStatement('DROP TABLE IF EXISTS vault_extension_tokens');
        self::assertTrue($factory->isTransient(VaultSettings::class));
        self::assertTrue($factory->isTransient(VaultExtensionToken::class));
        self::assertFalse($factory->isTransient(VaultItem::class));
        $mapped = array_map(static fn (ClassMetadata $meta): string => $meta->getName(), $factory->getAllMetadata());
        self::assertFalse(in_array(VaultSettings::class, $mapped, true));
        self::assertFalse(in_array(VaultExtensionToken::class, $mapped, true));
        self::assertTrue(in_array(VaultItem::class, $mapped, true));

        $connection = $em->getConnection();
        // sqlite_master, not the schema manager: the schema asset filter hides the unmapped tables from the latter.
        $tables = $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'");
        self::assertNotContains('vault_settings', $tables);
        self::assertNotContains('vault_extension_tokens', $tables);
        self::assertContains('vault_items', $tables);

        // A table left by an earlier release is hidden from the schema tools: never dropped, schema stays in sync.
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS vault_settings (id VARCHAR(64) NOT NULL PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS vault_extension_tokens (id VARCHAR(36) NOT NULL PRIMARY KEY)');
        self::assertNotContains('vault_settings', $connection->createSchemaManager()->listTableNames(), 'Hidden from the schema tools.');
        $tool = new SchemaTool($em);
        $sql  = $tool->getUpdateSchemaSql($factory->getAllMetadata());
        self::assertSame([], $sql, 'No DROP of the hidden tables, nothing else to change.');
        self::assertContains('vault_settings', $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table'"));

        $alice = $this->createUser('alice@example.com');
        $this->createItem($alice, 'Mail');
        $export = self::getContainer()->get('test.personal_data_exporter')->export((string) $alice->getId());
        self::assertSame([], $export['extension_tokens']);
        self::assertCount(1, $export['items']);
        $result = self::getContainer()->get('test.personal_data_eraser')->erase((string) $alice->getId());
        self::assertSame(0, $result->toArray()['extension_tokens']);
        self::assertSame(1, $result->toArray()['items']);

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('nowo:vault:extension-tokens:purge'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Removed 0 expired', $tester->getDisplay());

        $connection->executeStatement('DROP TABLE IF EXISTS vault_settings');
        $connection->executeStatement('DROP TABLE IF EXISTS vault_extension_tokens');
    }
}
