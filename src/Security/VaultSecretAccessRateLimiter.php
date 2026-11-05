<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use DateTimeImmutable;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash;
use function is_array;
use function is_int;

/**
 * Per-user fixed-window limit on requests that return decrypted secrets. The field reveal route and the browser
 * extension `/logins` lookup share ONE budget per user (the channel is only recorded in the audit event).
 *
 * The window starts at the first request and is not extended by later ones. The cache key is a hash of the user id, or
 * of the user identifier when the user object has no id (no user identifier is stored in clear in the cache). One
 * `/logins` request costs one unit, whatever the number of credentials it returns.
 *
 * Best effort: the cache read-modify-write is not atomic, so concurrent requests can slightly exceed the budget, and
 * the limit only holds when the cache pool is shared by every PHP process / worker and persistent (an array or
 * per-process pool gives each process its own counter).
 */
final readonly class VaultSecretAccessRateLimiter
{
    private const CACHE_PREFIX = 'nowo_vault_secret_access_';

    /**
     * Creates the limiter with its cache pool, the request budget per window and the window length.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $maxRequests,
        private int $windowSeconds,
        private bool $enabled,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Counts one secret-access request of the user (reveal or /logins alike); false when the window's budget is spent.
     */
    public function consume(UserInterface $user): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $now  = $this->now()->getTimestamp();
        $item = $this->cache->getItem(self::CACHE_PREFIX . hash('sha256', $this->userKey($user)));
        $data = $item->isHit() ? $item->get() : null;

        if (!is_array($data) || !is_int($data['count'] ?? null) || !is_int($data['reset'] ?? null) || $data['reset'] <= $now) {
            $data = ['count' => 0, 'reset' => $now + $this->windowSeconds];
        }

        if ($data['count'] >= $this->maxRequests) {
            return false;
        }

        ++$data['count'];
        $item->set($data);
        $item->expiresAt(new DateTimeImmutable('@' . $data['reset']));
        $this->cache->save($item);

        return true;
    }

    /**
     * Window length in seconds (the Retry-After hint of a refused request).
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Bucket key of the user: its id, else its security identifier (distinct prefixes so they never collide).
     */
    private function userKey(UserInterface $user): string
    {
        $id = UserIdResolver::getId($user);

        return $id !== null ? 'id|' . $id : 'identifier|' . $user->getUserIdentifier();
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
