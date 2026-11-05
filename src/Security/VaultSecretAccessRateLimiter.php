<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

use function hash;
use function is_array;
use function is_int;

/**
 * Per-user fixed-window limit on requests that return decrypted secrets (field reveal, browser extension /logins).
 *
 * The window starts at the first request and is not extended by later ones; the cache key is a hash of the channel
 * and the user id (no user identifier is stored in clear in the cache).
 */
final readonly class VaultSecretAccessRateLimiter
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_EXTENSION = 'extension';

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
     * Counts one request of the user on the channel; false when the window's budget is already spent.
     */
    public function consume(string $channel, string $userId): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $now  = $this->now()->getTimestamp();
        $item = $this->cache->getItem(self::CACHE_PREFIX . hash('sha256', $channel . '|' . $userId));
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

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
