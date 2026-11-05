<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Tests\App\ConfigStorageKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.15.2 on the config-storage kernel (security.deny_status: 404, secret_access_rate_limit.max_requests: 2, no
 * GRANT_LIST_QUERY listener and the default allow_unlisted_grantees: false).
 */
final class VaultDenyStatusEndToEndTest extends WebTestCase
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

    public function testInaccessibleItemsFoldersAndGrantsAnswer404(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $item   = $this->createItem($alice, 'Mail');
        $folder = $this->createFolder($alice, 'Work');
        $grant  = self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, 'someone', VaultPermission::Read, $this->managed($alice));

        $client->loginUser($bob);
        foreach (['/tools/vault/items/' . $item->getId(), '/tools/vault/items/' . $item->getId() . '/edit', '/tools/vault/items/' . $item->getId() . '/share', '/tools/vault/folders/' . $folder->getId() . '/share'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
            self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        }

        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/grants/' . $grant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $grant->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNotNull(self::getContainer()->get(VaultGrantRepositoryInterface::class)->findById($grant->getId()));

        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_' . $item->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSharingFailsClosedWithoutAGranteeListener(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $this->createUser('bob@example.com');
        $item = $this->createItem($alice, 'Mail');
        $client->loginUser($alice);

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-share-unavailable"]'));

        $form = $crawler->filterXPath('//form[.//*[@name="vault_share[granteeId]"]]')->form([
            'vault_share[granteeType]' => 'user',
            'vault_share[granteeId]'   => 'any-user-id',
            'vault_share[permission]'  => 'read',
        ]);
        $client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Item, $item->getId()));
    }

    public function testSecretAccessIsRateLimitedPerUser(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $item   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['websites' => ['https://example.com']]);
        self::getContainer()->get('test.vault_secret_access_cache')->clear();
        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_reveal_' . $item->getId());

        foreach ([Response::HTTP_OK, Response::HTTP_OK, Response::HTTP_TOO_MANY_REQUESTS] as $status) {
            $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseStatusCodeSame($status);
        }
        self::assertSame('60', $client->getResponse()->headers->get('Retry-After'));

        $client->request('POST', '/api/vault/extension/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['username' => 'alice@example.com', 'password' => 'secret'], JSON_THROW_ON_ERROR));
        /** @var array{token: string} $login */
        $login = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        foreach ([Response::HTTP_OK, Response::HTTP_OK, Response::HTTP_TOO_MANY_REQUESTS] as $status) {
            $client->request('GET', '/api/vault/extension/logins?domain=example.com', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $login['token']]);
            self::assertResponseStatusCodeSame($status);
        }
        self::assertSame('60', $client->getResponse()->headers->get('Retry-After'));
    }
}
