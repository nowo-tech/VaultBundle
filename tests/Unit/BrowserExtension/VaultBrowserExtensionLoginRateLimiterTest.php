<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\BrowserExtension;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionLoginRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class VaultBrowserExtensionLoginRateLimiterTest extends TestCase
{
    public function testBlocksAfterMaxAttempts(): void
    {
        $limiter = new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 3, 900, true);

        self::assertFalse($limiter->isLimited('127.0.0.1', 'alice@example.com'));

        $limiter->registerFailedAttempt('127.0.0.1', 'alice@example.com');
        $limiter->registerFailedAttempt('127.0.0.1', 'alice@example.com');
        self::assertFalse($limiter->isLimited('127.0.0.1', 'alice@example.com'));

        $limiter->registerFailedAttempt('127.0.0.1', 'alice@example.com');
        self::assertTrue($limiter->isLimited('127.0.0.1', 'alice@example.com'));
    }

    public function testResetClearsCounter(): void
    {
        $limiter = new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 2, 900, true);

        $limiter->registerFailedAttempt('10.0.0.1', 'bob@example.com');
        $limiter->registerFailedAttempt('10.0.0.1', 'bob@example.com');
        self::assertTrue($limiter->isLimited('10.0.0.1', 'bob@example.com'));

        $limiter->reset('10.0.0.1', 'bob@example.com');
        self::assertFalse($limiter->isLimited('10.0.0.1', 'bob@example.com'));
    }

    public function testDisabledLimiterNeverBlocks(): void
    {
        $limiter = new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 1, 900, false);

        $limiter->registerFailedAttempt('127.0.0.1', 'alice@example.com');
        self::assertFalse($limiter->isLimited('127.0.0.1', 'alice@example.com'));
    }

    public function testResetNoOpWhenDisabled(): void
    {
        $cache   = new ArrayAdapter();
        $limiter = new VaultBrowserExtensionLoginRateLimiter($cache, 2, 900, false);

        $enabledLimiter = new VaultBrowserExtensionLoginRateLimiter($cache, 2, 900, true);
        $enabledLimiter->registerFailedAttempt('10.0.0.2', 'carol@example.com');
        $enabledLimiter->registerFailedAttempt('10.0.0.2', 'carol@example.com');
        self::assertTrue($enabledLimiter->isLimited('10.0.0.2', 'carol@example.com'));

        $limiter->reset('10.0.0.2', 'carol@example.com');

        self::assertTrue($enabledLimiter->isLimited('10.0.0.2', 'carol@example.com'));
    }

    public function testPerIpBucketBlocksCredentialStuffingAcrossUsernames(): void
    {
        $limiter = new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 5, 900, true, 3);

        $limiter->registerFailedAttempt('203.0.113.7', 'user1@example.com');
        $limiter->registerFailedAttempt('203.0.113.7', 'user2@example.com');
        self::assertFalse($limiter->isLimited('203.0.113.7', 'user3@example.com'));

        $limiter->registerFailedAttempt('203.0.113.7', 'user3@example.com');

        // Every username is now blocked from this IP, even one never tried before.
        self::assertTrue($limiter->isLimited('203.0.113.7', 'user4@example.com'));
        self::assertTrue($limiter->isLimited('203.0.113.7', 'user1@example.com'));
        // Other IPs are unaffected.
        self::assertFalse($limiter->isLimited('198.51.100.1', 'user1@example.com'));
    }

    public function testSuccessfulLoginDoesNotResetPerIpBucket(): void
    {
        $limiter = new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 5, 900, true, 2);

        $limiter->registerFailedAttempt('203.0.113.8', 'user1@example.com');
        $limiter->registerFailedAttempt('203.0.113.8', 'user2@example.com');
        self::assertTrue($limiter->isLimited('203.0.113.8', 'valid@example.com'));

        $limiter->reset('203.0.113.8', 'user1@example.com');

        self::assertTrue($limiter->isLimited('203.0.113.8', 'user1@example.com'));
    }

    public function testPerIpBucketDisabledWithZero(): void
    {
        $limiter = new VaultBrowserExtensionLoginRateLimiter(new ArrayAdapter(), 2, 900, true, 0);

        for ($i = 0; $i < 10; ++$i) {
            $limiter->registerFailedAttempt('203.0.113.9', 'user' . $i . '@example.com');
        }

        self::assertFalse($limiter->isLimited('203.0.113.9', 'other@example.com'));
        self::assertFalse($limiter->isLimited('203.0.113.9', 'user0@example.com'));
    }

    public function testIgnoresNonArrayCacheValues(): void
    {
        $cache   = new ArrayAdapter();
        $limiter = new VaultBrowserExtensionLoginRateLimiter($cache, 1, 900, true, 1);
        $limiter->registerFailedAttempt('203.0.113.10', 'a@example.com');

        foreach ($cache->getValues() as $key => $unused) {
            $item = $cache->getItem($key);
            $item->set('corrupt');
            $cache->save($item);
        }

        self::assertFalse($limiter->isLimited('203.0.113.10', 'a@example.com'));
    }
}
