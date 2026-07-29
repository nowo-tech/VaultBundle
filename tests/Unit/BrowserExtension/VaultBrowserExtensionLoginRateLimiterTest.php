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
}
