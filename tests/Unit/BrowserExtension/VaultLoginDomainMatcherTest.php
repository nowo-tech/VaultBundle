<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\BrowserExtension;

use Nowo\VaultBundle\BrowserExtension\VaultLoginDomainMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VaultLoginDomainMatcherTest extends TestCase
{
    #[DataProvider('matchScoreProvider')]
    public function testMatchScore(?int $expected, string $current, string $stored): void
    {
        self::assertSame($expected, VaultLoginDomainMatcher::matchScore($current, $stored));
    }

    /**
     * @return iterable<string, array{0: ?int, 1: string, 2: string}>
     */
    public static function matchScoreProvider(): iterable
    {
        yield 'exact domain' => [1002, 'github.com', 'github.com'];
        yield 'exact subdomain beats parent' => [1003, 'login.github.com', 'login.github.com'];
        yield 'parent domain matches subdomain page' => [2, 'login.github.com', 'github.com'];
        yield 'sibling subdomain does not match' => [null, 'login.github.com', 'api.github.com'];
        yield 'unrelated domain' => [null, 'github.com', 'gitlab.com'];
        yield 'www stripped' => [1002, 'www.github.com', 'github.com'];
    }

    public function testBestMatchScorePrefersMostSpecific(): void
    {
        $matcher = new VaultLoginDomainMatcher();
        $score   = $matcher->bestMatchScore('login.github.com', [
            'https://github.com',
            'https://login.github.com',
        ]);

        self::assertSame(1003, $score);
    }

    /** SEC-004 H1: a stored https (or scheme-less) site is never offered on its plain-HTTP twin. */
    public function testHttpPagesOnlyMatchWebsitesStoredAsHttp(): void
    {
        $matcher = new VaultLoginDomainMatcher();

        self::assertNull($matcher->bestMatchScore('bank.example', ['https://bank.example', 'bank.example'], 'http'));
        self::assertNotNull($matcher->bestMatchScore('intranet.example', ['http://intranet.example'], 'http'));
        self::assertNotNull($matcher->bestMatchScore('bank.example', ['https://bank.example'], 'https'));
        self::assertNotNull($matcher->bestMatchScore('bank.example', ['bank.example']));
    }

    public function testExtractHostFromBareDomain(): void
    {
        self::assertSame('example.com', VaultLoginDomainMatcher::extractHost('example.com'));
        self::assertSame('example.com', VaultLoginDomainMatcher::extractHost('https://www.example.com/path'));
    }
}
