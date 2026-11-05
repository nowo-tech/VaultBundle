<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Security\VaultSecretAccessRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class VaultSecretAccessRateLimiterTest extends TestCase
{
    public function testBudgetPerWindowChannelAndUser(): void
    {
        $clock   = new MockClock('2026-11-05 10:00:00');
        $limiter = new VaultSecretAccessRateLimiter(new ArrayAdapter(), 2, 60, true, $clock);

        self::assertTrue($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
        self::assertTrue($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
        self::assertFalse($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));

        // Other user and other channel have their own budget.
        self::assertTrue($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u2'));
        self::assertTrue($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_EXTENSION, 'u1'));

        // A refused request does not extend the window; a new window starts after it.
        $clock->sleep(59);
        self::assertFalse($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
        $clock->sleep(1);
        self::assertTrue($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
        self::assertSame(60, $limiter->getWindowSeconds());
    }

    public function testDisabledAndCorruptCacheEntries(): void
    {
        $disabled = new VaultSecretAccessRateLimiter(new ArrayAdapter(), 1, 60, false);
        self::assertTrue($disabled->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
        self::assertTrue($disabled->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));

        $cache = new ArrayAdapter();
        $item  = $cache->getItem('nowo_vault_secret_access_' . hash('sha256', 'web|u1'));
        $item->set(['count' => 'x']);
        $cache->save($item);

        $limiter = new VaultSecretAccessRateLimiter($cache, 1, 60, true);
        self::assertTrue($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
        self::assertFalse($limiter->consume(VaultSecretAccessRateLimiter::CHANNEL_WEB, 'u1'));
    }
}
