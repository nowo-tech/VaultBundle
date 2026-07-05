<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\BrowserExtension;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VaultBrowserExtensionResponseFactoryTest extends TestCase
{
    #[DataProvider('originProvider')]
    public function testCorsHeaderAppliedForAllowedOrigins(array $allowedOrigins, string $origin, bool $expectsCors, string $environment = 'prod'): void
    {
        $factory  = new VaultBrowserExtensionResponseFactory($allowedOrigins, $environment);
        $request  = Request::create('/api/vault/extension/login', 'POST', server: ['HTTP_ORIGIN' => $origin]);
        $response = $factory->json(['ok' => true], 200, $request);

        if ($expectsCors) {
            self::assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
            self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        } else {
            self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        }
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string, 2: bool, 3?: string}>
     */
    public static function originProvider(): iterable
    {
        yield 'chrome extension default allowlist' => [[], 'chrome-extension://abc', true];
        yield 'moz extension default allowlist' => [[], 'moz-extension://abc', true];
        yield 'http origin blocked by default' => [[], 'https://evil.example', false];
        yield 'wildcard allows any origin in dev' => [['*'], 'https://evil.example', true, 'dev'];
        yield 'wildcard blocked in prod' => [['*'], 'https://evil.example', false, 'prod'];
        yield 'explicit whitelist' => [['https://app.example'], 'https://app.example', true, 'prod'];
        yield 'explicit whitelist rejects other' => [['https://app.example'], 'https://other.example', false, 'prod'];
    }

    public function testNoCorsWithoutOriginHeader(): void
    {
        $factory  = new VaultBrowserExtensionResponseFactory(['*'], 'dev');
        $response = $factory->json(['ok' => true], 200, Request::create('/test'));

        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
