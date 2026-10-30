<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ReadOnlyTitleSubscriber;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;

use function file_put_contents;
use function json_decode;
use function json_encode;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Functional coverage of VaultManageController through the routed kernel (REQ-TEST-003).
 */
final class ManageVaultControllerEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const UNKNOWN_ID = '00000000-0000-4000-8000-000000000000';

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testHomeAndListPagesRender(): void
    {
        [$client, $alice] = $this->loggedIn();
        $folder           = $this->createFolder($alice, 'Work');
        $item             = $this->createItem($alice, 'GitHub', folder: $folder);
        $tagService       = self::getContainer()->get(VaultTagService::class);
        $tagService->syncItemTags($item, $this->managed($alice), ['ops']);
        $tag = self::getContainer()->get(VaultTagRepositoryInterface::class)->findOneByCreatorAndName($alice, 'ops');
        self::assertNotNull($tag);

        $client->request('GET', '/tools/vault');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/tools/vault/runtime-config', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GitHub', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tools/vault/items?folder=' . $folder->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GitHub', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tools/vault/items?tag=' . $tag->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GitHub', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tools/vault/items?q=git');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('GitHub', (string) $client->getResponse()->getContent());
        // Search is capped, not paginated.
        self::assertStringNotContainsString('nowo-vault-pagination', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tools/vault/shared');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/tools/vault/trash?page=0');
        self::assertResponseIsSuccessful();
    }

    public function testCreateLoginItemInOwnFolder(): void
    {
        [$client, $alice] = $this->loggedIn();
        $folder           = $this->createFolder($alice, 'Work');

        $crawler = $client->request('GET', '/tools/vault/items/new/login?folder=' . $folder->getId());
        self::assertResponseIsSuccessful();
        $form = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]'       => 'Mail',
            'vault_item_form[username]'    => 'alice',
            'vault_item_form[password]'    => 'REDACTED-TEST',
            'vault_item_form[websites][0]' => 'https://mail.example.com',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/tools/vault/items?folder=' . $folder->getId());
        $items = $this->itemsOf($alice);
        self::assertCount(1, $items);
        self::assertSame('Mail', $items[0]->getTitle());
        self::assertSame($folder->getId(), $items[0]->getFolder()?->getId());
    }

    public function testInvalidItemIsRenderedAgainWith422(): void
    {
        [$client, $alice] = $this->loggedIn();

        $crawler = $client->request('GET', '/tools/vault/items/new/login');
        self::assertResponseIsSuccessful();
        $crawler = $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-item-form"]//input[@name="vault_item_form[title]"]'));
        self::assertCount(0, $this->itemsOf($alice));
    }

    public function testNewItemIgnoresForeignTrashedAndUnknownFolders(): void
    {
        [$client, $alice] = $this->loggedIn();
        $bob              = $this->createUser('bob@example.com');
        $foreign          = $this->createFolder($bob, 'Bob');
        $trashed          = $this->createFolder($alice, 'Old');
        self::getContainer()->get(VaultTrashService::class)->moveFolderToTrash($trashed);

        foreach ([$foreign->getId(), $trashed->getId(), self::UNKNOWN_ID] as $folderId) {
            $crawler = $client->request('GET', '/tools/vault/items/new/secure_note?folder=' . $folderId);
            self::assertResponseIsSuccessful();
            $selected = $crawler->filterXPath('//select[@name="vault_item_form[folder]"]/option[@selected]');
            self::assertCount(0, $selected, 'folder ' . $folderId . ' must not be preselected');
        }
    }

    public function testUnknownItemTypeIsNotFound(): void
    {
        [$client] = $this->loggedIn();

        $client->request('GET', '/tools/vault/items/new/unknown_type');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDocumentAttachmentIsEncryptedKeptOnEditAndRejectedWhenTooLarge(): void
    {
        [$client, $alice] = $this->loggedIn();
        $path             = (string) tempnam(sys_get_temp_dir(), 'vault');
        file_put_contents($path, 'scan-bytes');

        $crawler = $client->request('GET', '/tools/vault/items/new/document');
        $form    = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]' => 'Passport scan',
        ]);
        $form['attachment']->upload($path);
        $client->submit($form);
        self::assertResponseRedirects('/tools/vault/items');

        $item    = $this->itemsOf($alice)[0];
        $crypto  = self::getContainer()->get(VaultPayloadCryptographerInterface::class);
        $payload = $crypto->decrypt($item->getCiphertext());
        self::assertSame('scan-bytes', base64_decode((string) $payload['attachmentContent'], true));

        // Editing without a new file keeps the stored attachment.
        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/edit');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]' => 'Passport scan v2',
        ]));
        self::assertResponseRedirects('/tools/vault/items');
        $reloaded = $this->findItem($item->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Passport scan v2', $reloaded->getTitle());
        self::assertSame('scan-bytes', base64_decode((string) $crypto->decrypt($reloaded->getCiphertext())['attachmentContent'], true));

        // A document without any file is stored without attachment.
        $crawler = $client->request('GET', '/tools/vault/items/new/id_card');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]' => 'ID',
        ]));
        self::assertResponseRedirects('/tools/vault/items');

        file_put_contents($path, str_repeat('x', 512_001));
        $crawler = $client->request('GET', '/tools/vault/items/new/document');
        $form    = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Too big']);
        $form['attachment']->upload($path);
        $client->submit($form);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        unlink($path);
    }

    public function testOwnerViewsAndEditsItemAndMovesIt(): void
    {
        [$client, $alice] = $this->loggedIn();
        $folder           = $this->createFolder($alice, 'Work');
        $item             = $this->createItem($alice, 'Card', VaultItemType::CreditCard, ['cardNumber' => '4111 REDACTED']);

        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Card', (string) $client->getResponse()->getContent());

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]'  => 'Card (work)',
            'vault_item_form[folder]' => $folder->getId(),
        ]));

        self::assertResponseRedirects('/tools/vault/items?folder=' . $folder->getId());
        $reloaded = $this->findItem($item->getId());
        self::assertSame('Card (work)', $reloaded?->getTitle());
        self::assertSame($folder->getId(), $reloaded?->getFolder()?->getId());
    }

    public function testGranteeAccessRules(): void
    {
        [$client, $alice] = $this->loggedIn();
        $bob              = $this->createUser('bob@example.com');
        $aliceFolder      = $this->createFolder($alice, 'Alice folder');
        $readItem         = $this->createItem($alice, ReadOnlyTitleSubscriber::PREFIX . 'Read only', folder: $aliceFolder);
        $writeItem        = $this->createItem($alice, 'Writable', folder: $aliceFolder);
        $private          = $this->createItem($alice, 'Private');
        $grants           = self::getContainer()->get(VaultGrantService::class);
        $grants->grant(VaultResourceType::Item, $readItem->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $grants->grant(VaultResourceType::Item, $writeItem->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Write, $this->managed($alice));
        $bobFolder = $this->createFolder($bob, 'Bob folder');

        $client->loginUser($bob);
        $client->request('GET', '/tools/vault/shared');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Read only', (string) $client->getResponse()->getContent());

        // Read grant: the edit page renders read-only and a submission is refused.
        $crawler = $client->request('GET', '/tools/vault/items/' . $readItem->getId() . '/edit');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Write grant: content changes, the owner's folder stays.
        $crawler = $client->request('GET', '/tools/vault/items/' . $writeItem->getId() . '/edit');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]' => 'Written by Bob',
        ]));
        self::assertResponseRedirects('/tools/vault/items');
        $reloaded = $this->findItem($writeItem->getId());
        self::assertSame('Written by Bob', $reloaded?->getTitle());
        self::assertSame($aliceFolder->getId(), $reloaded?->getFolder()?->getId());

        // No grant at all.
        $client->request('GET', '/tools/vault/items/' . $private->getId() . '/edit');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->request('GET', '/tools/vault/items/' . $private->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->request('GET', '/tools/vault/folders/' . $aliceFolder->getId() . '/share');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Bob's own folder is shareable by him; unknown ids are 404.
        $client->request('GET', '/tools/vault/folders/' . $bobFolder->getId() . '/share');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/tools/vault/items/' . self::UNKNOWN_ID);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/tools/vault/folders/' . self::UNKNOWN_ID . '/share');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testShareAndRevokeItemGrant(): void
    {
        [$client, $alice] = $this->loggedIn();
        $bob              = $this->createUser('bob@example.com');
        $folder           = $this->createFolder($alice, 'Work');
        $item             = $this->createItem($alice, 'Shared login', folder: $folder);

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        self::assertResponseIsSuccessful();
        $form = $this->shareForm($crawler, (string) $bob->getId(), 'write');
        $client->submit($form);
        self::assertResponseRedirects('/tools/vault/items/' . $item->getId() . '/share');

        $grants = self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Item, $item->getId());
        self::assertCount(1, $grants);
        self::assertSame(VaultPermission::Write, $grants[0]->getPermission());

        // Sharing with yourself is refused.
        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        $client->submit($this->shareForm($crawler, (string) $alice->getId(), 'admin'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Item, $item->getId()));

        $grantId = $grants[0]->getId();
        $base    = '/tools/vault/items/' . $item->getId() . '/grants/';

        $client->request('POST', $base . $grantId . '/revoke');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request('POST', $base . self::UNKNOWN_ID . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . self::UNKNOWN_ID)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // A grant of another resource cannot be revoked through this item.
        $other      = $this->createItem($alice, 'Other');
        $otherGrant = self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $other->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $client->request('POST', $base . $otherGrant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $otherGrant->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('POST', $base . $grantId . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $grantId)]);
        self::assertResponseRedirects('/tools/vault/items/' . $item->getId() . '/share');
        self::assertSame([], self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Item, $item->getId()));
    }

    public function testShareAndRevokeFolderGrant(): void
    {
        [$client, $alice] = $this->loggedIn();
        $bob              = $this->createUser('bob@example.com');
        $folder           = $this->createFolder($alice, 'Team');

        $crawler = $client->request('GET', '/tools/vault/folders/' . $folder->getId() . '/share');
        self::assertResponseIsSuccessful();
        $client->submit($this->shareForm($crawler, (string) $bob->getId(), 'read'));
        self::assertResponseRedirects('/tools/vault/items?folder=' . $folder->getId());

        $grants = self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Folder, $folder->getId());
        self::assertCount(1, $grants);
        $base = '/tools/vault/folders/' . $folder->getId() . '/grants/';

        // The share page lists the grant with its revoke form.
        $client->request('GET', '/tools/vault/folders/' . $folder->getId() . '/share');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($base . $grants[0]->getId() . '/revoke', (string) $client->getResponse()->getContent());

        $client->request('POST', $base . self::UNKNOWN_ID . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . self::UNKNOWN_ID)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $client->request('POST', $base . $grants[0]->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $grants[0]->getId())]);
        self::assertResponseRedirects('/tools/vault/items?folder=' . $folder->getId());
        self::assertSame([], self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Folder, $folder->getId()));
    }

    public function testTrashRestoreAndPurgeItem(): void
    {
        [$client, $alice] = $this->loggedIn();
        $item             = $this->createItem($alice, 'Disposable');
        $id               = $item->getId();

        $client->request('POST', '/tools/vault/items/' . $id . '/trash', ['_token' => $this->csrf($client, 'vault_trash_' . $id)]);
        self::assertResponseRedirects('/tools/vault/items');
        self::assertTrue($this->findItem($id)?->isDeleted());

        $client->request('GET', '/tools/vault/trash');
        self::assertStringContainsString('Disposable', (string) $client->getResponse()->getContent());

        $client->request('POST', '/tools/vault/items/' . $id . '/restore', ['_token' => $this->csrf($client, 'vault_restore_' . $id)]);
        self::assertResponseRedirects('/tools/vault/trash');
        self::assertFalse($this->findItem($id)?->isDeleted());

        $client->request('POST', '/tools/vault/items/' . $id . '/purge', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request('POST', '/tools/vault/items/' . $id . '/purge', ['_token' => $this->csrf($client, 'vault_purge_' . $id)]);
        self::assertResponseRedirects('/tools/vault/trash');
        self::assertNull($this->findItem($id));
    }

    public function testCreateAndTrashFolders(): void
    {
        [$client, $alice] = $this->loggedIn();

        $client->request('POST', '/tools/vault/folders', ['name' => '  ', '_token' => $this->csrf($client, 'vault_folder_create')]);
        self::assertResponseRedirects('/tools/vault/items');
        self::assertSame([], $this->foldersOf($alice));

        $client->request('POST', '/tools/vault/folders', ['name' => 'Finance', '_token' => $this->csrf($client, 'vault_folder_create')]);
        self::assertResponseRedirects('/tools/vault/items');
        $folders = $this->foldersOf($alice);
        self::assertCount(1, $folders);

        $withItem = $folders[0];
        $item     = $this->createItem($alice, 'Bank', folder: $withItem);
        $empty    = $this->createFolder($alice, 'Empty');

        $client->request('POST', '/tools/vault/folders/' . $withItem->getId() . '/trash', ['_token' => $this->csrf($client, 'vault_folder_trash_' . $withItem->getId())]);
        self::assertResponseRedirects('/tools/vault/items');
        self::assertNull($this->findItem($item->getId())?->getFolder());

        $client->request('POST', '/tools/vault/folders/' . $empty->getId() . '/trash', ['_token' => $this->csrf($client, 'vault_folder_trash_' . $empty->getId())]);
        self::assertResponseRedirects('/tools/vault/items');
        self::assertSame([], $this->foldersOf($alice));

        // A trashed folder is no longer shareable.
        $client->request('GET', '/tools/vault/folders/' . $empty->getId() . '/share');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDeleteTagKeepsListFilters(): void
    {
        [$client, $alice] = $this->loggedIn();
        $item             = $this->createItem($alice, 'Tagged');
        $tags             = self::getContainer()->get(VaultTagService::class);
        $tags->syncItemTags($item, $this->managed($alice), ['a', 'b']);
        $repo = self::getContainer()->get(VaultTagRepositoryInterface::class);
        $tagA = $repo->findOneByCreatorAndName($alice, 'a');
        $tagB = $repo->findOneByCreatorAndName($alice, 'b');
        self::assertNotNull($tagA);
        self::assertNotNull($tagB);

        $client->request('POST', '/tools/vault/tags/' . $tagA->getId() . '/delete', [
            '_token' => $this->csrf($client, 'vault_tag_delete_' . $tagA->getId()),
            'folder' => self::UNKNOWN_ID,
            'q'      => 'tag',
            'tag'    => $tagB->getId(),
        ]);
        self::assertResponseRedirects('/tools/vault/items?folder=' . self::UNKNOWN_ID . '&q=tag&tag=' . $tagB->getId());
        self::assertNull($repo->findOneByCreatorAndName($alice, 'a'));

        // The deleted tag itself is dropped from the redirect filters.
        $client->request('POST', '/tools/vault/tags/' . $tagB->getId() . '/delete?tag=' . $tagB->getId(), [
            '_token' => $this->csrf($client, 'vault_tag_delete_' . $tagB->getId()),
        ]);
        self::assertResponseRedirects('/tools/vault/items');

        $client->request('POST', '/tools/vault/tags/' . self::UNKNOWN_ID . '/delete', ['_token' => $this->csrf($client, 'vault_tag_delete_' . self::UNKNOWN_ID)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGeneratePasswordAcceptsJsonFormAndHeaderTokens(): void
    {
        [$client] = $this->loggedIn();
        $token    = $this->csrf($client, 'vault_password_generate');

        $client->request('POST', '/tools/vault/password/generate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            '_token' => $token,
            'mode'   => 'characters',
            'length' => 20,
        ], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        /** @var array{password: string, strength: mixed} $json */
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(20, mb_strlen($json['password']));
        self::assertArrayHasKey('strength', $json);

        $client->request('POST', '/tools/vault/password/generate', ['mode' => 'characters', 'length' => '12'], server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        $json = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(12, mb_strlen($json['password']));

        $client->request('POST', '/tools/vault/password/generate', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['_token' => 42], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /**
     * @return array{0: KernelBrowser, 1: User}
     */
    private function loggedIn(): array
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $client->loginUser($alice);

        return [$client, $alice];
    }

    /**
     * @return list<VaultItem>
     */
    private function itemsOf(User $user): array
    {
        return self::getContainer()->get(VaultItemRepositoryInterface::class)->findByCreator($user);
    }

    /**
     * @return list<VaultFolder>
     */
    private function foldersOf(User $user): array
    {
        return self::getContainer()->get(VaultFolderRepositoryInterface::class)->findByCreator($user);
    }

    private function shareForm(Crawler $crawler, string $granteeId, string $permission): Form
    {
        return $crawler->filterXPath('//form[.//*[@name="vault_share[granteeId]"]]')->form([
            'vault_share[granteeType]' => 'user',
            'vault_share[granteeId]'   => $granteeId,
            'vault_share[permission]'  => $permission,
        ]);
    }
}
