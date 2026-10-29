<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionLoginRateLimiter;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use Nowo\VaultBundle\Controller\VaultBrowserExtensionController;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Service\VaultBrowserExtensionLoginResolver;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Browser extension API branches not exercised by the main flow: preflight on every endpoint, missing fields,
 * rate limiting (test kernel: max_attempts 3), malformed Bearer headers and token-less logout.
 */
final class BrowserExtensionApiEdgeCasesEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testPreflightOnEveryEndpoint(): void
    {
        $client = $this->bootClient();

        foreach (['login', 'logins', 'logout', 'me'] as $endpoint) {
            $client->request('OPTIONS', '/api/vault/extension/' . $endpoint, server: ['HTTP_ORIGIN' => 'chrome-extension://test-id']);
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, $endpoint);
        }
    }

    public function testLoginRequiresUsernameAndPassword(): void
    {
        $client = $this->bootClient();

        $this->login($client, ['username' => 'alice@example.com']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $client->request('POST', '/api/vault/extension/login', server: ['CONTENT_TYPE' => 'application/json'], content: 'not json');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /**
     * The test kernel's cache.app is an ArrayAdapter reset between requests, so the limiter gets its own pool here.
     */
    public function testRepeatedFailuresAreRateLimitedEvenWithTheRightPassword(): void
    {
        $this->bootClient();
        $this->createUser('carol@example.com');
        $controller = new VaultBrowserExtensionController(
            self::getContainer()->get(VaultBrowserExtensionAuthService::class),
            self::getContainer()->get(VaultBrowserExtensionLoginResolver::class),
            self::getContainer()->get(VaultBrowserExtensionResponseFactory::class),
            new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 3, 3600, true),
        );
        $request = static fn (string $password): Request => Request::create('/api/vault/extension/login', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7'], content: json_encode(['username' => 'carol@example.com', 'password' => $password], JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $controller->login($request('secret'))->getStatusCode());
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(Response::HTTP_UNAUTHORIZED, $controller->login($request('wrong'))->getStatusCode());
        }

        $limited = $controller->login($request('secret'));
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $limited->getStatusCode());
        self::assertStringNotContainsString('token', (string) $limited->getContent());
    }

    public function testMalformedBearerHeadersAreUnauthorizedAndLogoutWithoutTokenIsNoop(): void
    {
        $client = $this->bootClient();
        $this->createUser('dave@example.com');
        $this->login($client, ['username' => 'dave@example.com', 'password' => 'secret']);
        self::assertResponseIsSuccessful();
        /** @var array{token: string} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        foreach (['', 'Basic abc', 'Bearer    ', 'Bearer not-a-token'] as $header) {
            $client->request('GET', '/api/vault/extension/me', server: ['HTTP_AUTHORIZATION' => $header]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, $header);
        }

        $client->request('POST', '/api/vault/extension/logout');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // The issued token still works: a token-less logout revoked nothing.
        $client->request('GET', '/api/vault/extension/logins?domain=example.com&scheme=http', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $payload['token']]);
        self::assertResponseIsSuccessful();
        /** @var array{domain: string, logins: list<mixed>} $logins */
        $logins = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('example.com', $logins['domain']);
        self::assertSame([], $logins['logins']);
    }

    /**
     * @param array<string, string> $body
     */
    private function login(KernelBrowser $client, array $body): void
    {
        $client->request('POST', '/api/vault/extension/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
