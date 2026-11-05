<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Security\VaultSecretAccessRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class VaultSecretAccessRateLimiterTest extends TestCase
{
    public function testBudgetPerWindowAndUser(): void
    {
        // Current time: ArrayAdapter expires entries by the real clock, so a fixed past date would expire them at once.
        $clock   = new MockClock();
        $limiter = new VaultSecretAccessRateLimiter(new ArrayAdapter(), 2, 60, true, $clock);
        $u1      = $this->userWithId(1, 'u1@example.com');

        self::assertTrue($limiter->consume($u1));
        self::assertTrue($limiter->consume($u1));
        self::assertFalse($limiter->consume($u1));

        // Another user has its own budget.
        self::assertTrue($limiter->consume($this->userWithId(2, 'u2@example.com')));

        // A refused request does not extend the window; a new window starts after it.
        $clock->sleep(59);
        self::assertFalse($limiter->consume($u1));
        $clock->sleep(1);
        self::assertTrue($limiter->consume($u1));
        self::assertSame(60, $limiter->getWindowSeconds());
    }

    public function testUsersWithoutIdAreKeyedByIdentifier(): void
    {
        $limiter = new VaultSecretAccessRateLimiter(new ArrayAdapter(), 1, 60, true);

        self::assertTrue($limiter->consume(new InMemoryUser('alice', null)));
        self::assertFalse($limiter->consume(new InMemoryUser('alice', null)));

        // Users without an id do not share one bucket.
        self::assertTrue($limiter->consume(new InMemoryUser('bob', null)));

        // An identifier never collides with an id of the same text.
        self::assertTrue($limiter->consume($this->userWithId('alice', 'other@example.com')));
    }

    public function testDisabledAndCorruptCacheEntries(): void
    {
        $user     = $this->userWithId(1, 'u1@example.com');
        $disabled = new VaultSecretAccessRateLimiter(new ArrayAdapter(), 1, 60, false);
        self::assertTrue($disabled->consume($user));
        self::assertTrue($disabled->consume($user));

        $cache = new ArrayAdapter();
        $item  = $cache->getItem('nowo_vault_secret_access_' . hash('sha256', 'id|1'));
        $item->set(['count' => 'x']);
        $cache->save($item);

        $limiter = new VaultSecretAccessRateLimiter($cache, 1, 60, true);
        self::assertTrue($limiter->consume($user));
        self::assertFalse($limiter->consume($user));
    }

    private function userWithId(int|string $id, string $identifier): UserInterface
    {
        return new class($id, $identifier) implements UserInterface {
            public function __construct(private readonly int|string $id, private readonly string $identifier)
            {
            }

            public function getId(): int|string
            {
                return $this->id;
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function eraseCredentials(): void
            {
            }
        };
    }
}
