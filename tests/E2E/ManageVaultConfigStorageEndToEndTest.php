<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\App\ConfigStorageKernel;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function str_contains;

/**
 * Pager, dashboard link and runtime config screen on the config-storage test kernel (per_page = 2, admin = ROLE_USER).
 */
final class ManageVaultConfigStorageEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    protected static function getKernelClass(): string
    {
        return ConfigStorageKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testListsArePaginated(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        foreach (['A', 'B', 'C'] as $title) {
            $this->createItem($alice, 'Item ' . $title);
        }
        $client->loginUser($alice);

        $crawler = $client->request('GET', '/tools/vault/items');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//*[contains(@class, "nowo-vault-pagination")]'));
        self::assertCount(1, $crawler->filterXPath('//a[@rel="next"][@href="/tools/vault/items?page=2"]'));
        self::assertCount(0, $crawler->filterXPath('//a[@rel="prev"]'));
        self::assertStringContainsString('Page 1 of 2 (3 entries)', (string) $client->getResponse()->getContent());

        $pageOne = (string) $client->getResponse()->getContent();

        $crawler = $client->request('GET', '/tools/vault/items?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//a[@rel="prev"][@href="/tools/vault/items?page=1"]'));
        self::assertCount(0, $crawler->filterXPath('//a[@rel="next"]'));
        $pageTwo = (string) $client->getResponse()->getContent();
        // Every item is on exactly one page.
        foreach (['A', 'B', 'C'] as $title) {
            self::assertSame(1, (int) str_contains($pageOne, 'Item ' . $title) + (int) str_contains($pageTwo, 'Item ' . $title), 'Item ' . $title);
        }

        $trash = self::getContainer()->get(VaultTrashService::class);
        foreach ($this->itemsOf($alice) as $item) {
            $trash->moveItemToTrash($item);
        }
        $client->request('GET', '/tools/vault/trash?page=2');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Page 2 of 2 (3 entries)', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tools/vault/shared');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('nowo-vault-pagination', (string) $client->getResponse()->getContent());
    }

    public function testHomeLinksRuntimeConfigAndDashboard(): void
    {
        $client = $this->bootClient();
        $client->loginUser($this->createUser('alice@example.com'));

        $crawler = $client->request('GET', '/tools/vault');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//a[@href="/tools/vault/runtime-config"]'));
    }

    public function testRuntimeConfigScreenSavesSettings(): void
    {
        $client = $this->bootClient();

        $client->request('GET', '/tools/vault/runtime-config');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $client->loginUser($this->createUser('alice@example.com'));
        $client->request('GET', '/tools/vault/runtime-config');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/tools/vault/runtime-config', ['max_attachment_bytes' => '2048', 'password_level' => '']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request('POST', '/tools/vault/runtime-config', [
            '_token'               => $this->csrf($client, 'vault_runtime_config'),
            'max_attachment_bytes' => '-1',
        ]);
        self::assertResponseRedirects('/tools/vault/runtime-config');
        self::assertNull(self::getContainer()->get(VaultSettingsRepositoryInterface::class)->findByScope());

        $client->request('POST', '/tools/vault/runtime-config', [
            '_token'               => $this->csrf($client, 'vault_runtime_config'),
            'max_attachment_bytes' => '2048',
            'password_level'       => '',
        ]);
        self::assertResponseRedirects('/tools/vault/runtime-config');
        $settings = self::getContainer()->get(VaultSettingsRepositoryInterface::class)->findByScope();
        self::assertNotNull($settings);
        self::assertSame(2048, $settings->getValues()['max_attachment_bytes']);
        self::assertSame('medium', $settings->getValues()['password_field']['level']);

        // The stored values are shown back.
        $crawler = $client->request('GET', '/tools/vault/runtime-config');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//input[@name="max_attachment_bytes"][@value="2048"]'));
    }

    /**
     * @return list<VaultItem>
     */
    private function itemsOf(User $user): array
    {
        return self::getContainer()->get(VaultItemRepositoryInterface::class)->findByCreator($this->managed($user));
    }
}
