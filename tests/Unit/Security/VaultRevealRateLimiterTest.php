<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Event\VaultRevealThrottledEvent;
use Nowo\VaultBundle\Security\VaultRevealRateLimiter;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\UserInterface;

final class VaultRevealRateLimiterTest extends TestCase
{
    public function testPerItemFieldWindowThenReset(): void
    {
        $clock   = new MockClock();
        $limiter = new VaultRevealRateLimiter(new ArrayAdapter(), 100, 1000, 2, true, $clock);
        $user    = new TestUser('1');

        self::assertNull($limiter->consume($user, 'i', 'password'));
        self::assertNull($limiter->consume($user, 'i', 'password'));
        self::assertSame(['window' => VaultRevealThrottledEvent::WINDOW_FIELD, 'retryAfter' => 60], $limiter->consume($user, 'i', 'password'));
        self::assertNull($limiter->consume($user, 'i', 'note'), 'Another field has its own window.');
        $clock->modify('+20 seconds');
        self::assertSame(40, $limiter->consume($user, 'i', 'password')['retryAfter'] ?? null);
        $clock->modify('+41 seconds');
        self::assertNull($limiter->consume($user, 'i', 'password'));
    }

    public function testMinuteAndHourWindowsAndIndependentUsers(): void
    {
        $clock   = new MockClock();
        $limiter = new VaultRevealRateLimiter(new ArrayAdapter(), 2, 3, 10, true, $clock);
        $alice   = new TestUser('1');

        self::assertNull($limiter->consume($alice, 'a', 'password'));
        self::assertNull($limiter->consume($alice, 'b', 'password'));
        self::assertSame(VaultRevealThrottledEvent::WINDOW_MINUTE, $limiter->consume($alice, 'c', 'password')['window'] ?? null);
        self::assertNull($limiter->consume(new TestUser('2'), 'a', 'password'), 'Another user has its own budget.');

        $clock->modify('+61 seconds');
        self::assertNull($limiter->consume($alice, 'c', 'password'));
        self::assertSame(['window' => VaultRevealThrottledEvent::WINDOW_HOUR, 'retryAfter' => 3539], $limiter->consume($alice, 'd', 'password'));
    }

    public function testDisabledAndCorruptEntries(): void
    {
        $cache = new ArrayAdapter();
        self::assertNull((new VaultRevealRateLimiter($cache, 1, 1, 1, false))->consume(new TestUser('1'), 'i', 'f'));

        $limiter = new VaultRevealRateLimiter($cache, 1, 10, 10);
        $user    = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'no-id@example.com';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }
        };
        self::assertNull($limiter->consume($user, 'i', 'f'));
        self::assertSame(VaultRevealThrottledEvent::WINDOW_MINUTE, $limiter->consume($user, 'j', 'f')['window'] ?? null);

        // A corrupt cache entry starts a new window instead of failing.
        foreach (['corrupt' => 'not-an-array'] as $value) {
            $fresh = new ArrayAdapter();
            $item  = $fresh->getItem('nowo_vault_reveal_' . hash('sha256', 'u|1|m'));
            $item->set($value);
            $fresh->save($item);
            self::assertNull((new VaultRevealRateLimiter($fresh, 1, 10, 10))->consume(new TestUser('1'), 'i', 'f'));
        }
    }
}
