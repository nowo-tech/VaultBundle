<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use const JSON_THROW_ON_ERROR;

#[Group('http-e2e')]
final class HttpBrowserExtensionApiTest extends TestCase
{
    private HttpClientInterface $client;

    private string $baseUrl;

    protected function setUp(): void
    {
        if (getenv('DEMO_HTTP_E2E') !== '1') {
            self::markTestSkipped('Set DEMO_HTTP_E2E=1 to run HTTP E2E tests against a running demo container.');
        }

        $this->baseUrl = getenv('DEMO_HTTP_BASE_URL') ?: 'http://127.0.0.1';
        $this->client  = HttpClient::create([
            'base_uri' => $this->baseUrl,
            'timeout'  => 5,
        ]);

        try {
            $this->client->request('GET', '/tools/vault');
        } catch (Throwable $e) {
            self::markTestSkipped('Demo is not reachable at ' . $this->baseUrl . ': ' . $e->getMessage());
        }
    }

    public function testHttpExtensionLoginAndLoginsAgainstRunningDemo(): void
    {
        $loginResponse = $this->client->request('POST', '/api/vault/extension/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode(['username' => 'demo@example.com', 'password' => 'demo'], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(200, $loginResponse->getStatusCode());

        /** @var array{token: string} $login */
        $login = $loginResponse->toArray();
        self::assertNotSame('', $login['token']);

        $loginsResponse = $this->client->request('GET', '/api/vault/extension/logins', [
            'query'   => ['domain' => 'login.example.com'],
            'headers' => ['Authorization' => 'Bearer ' . $login['token']],
        ]);

        self::assertSame(200, $loginsResponse->getStatusCode());

        /** @var array{logins: list<array{title: string}>} $payload */
        $payload = $loginsResponse->toArray();
        self::assertNotEmpty($payload['logins']);
        self::assertSame('Demo specific login', $payload['logins'][0]['title']);
    }

    public function testHttpExtensionCorsPreflight(): void
    {
        $response = $this->client->request('OPTIONS', '/api/vault/extension/login', [
            'headers' => ['Origin' => 'moz-extension://vault-bundle-test'],
        ]);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            'moz-extension://vault-bundle-test',
            $response->getHeaders()['access-control-allow-origin'][0] ?? null,
        );
    }
}
