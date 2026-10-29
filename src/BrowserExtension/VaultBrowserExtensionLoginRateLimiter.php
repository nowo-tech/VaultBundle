<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use Psr\Cache\CacheItemPoolInterface;

use function hash;
use function is_array;

/**
 * Cache-backed brute-force protection for the browser extension login.
 *
 * Two buckets are tracked per failed attempt:
 *  - client IP + username ({@see $maxAttempts}): targeted guessing of one account;
 *  - client IP only ({@see $maxAttemptsPerIp}, 0 disables): credential stuffing
 *    across many usernames from the same address.
 *
 * A successful login only clears the IP + username bucket, so a valid account
 * cannot be used to reset the per-IP counter.
 */
final readonly class VaultBrowserExtensionLoginRateLimiter
{
    private const CACHE_PREFIX    = 'nowo_vault_ext_login_';
    private const IP_CACHE_PREFIX = 'nowo_vault_ext_login_ip_';

    /**
     * Creates the limiter with its cache pool, per IP+username and per IP thresholds and lockout window.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $maxAttempts,
        private int $intervalSeconds,
        private bool $enabled,
        private int $maxAttemptsPerIp = 0,
    ) {
    }

    /**
     * Whether further login attempts from this IP (and username) are currently locked out.
     */
    public function isLimited(string $clientIp, string $username): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->maxAttempts > 0 && $this->readCount($this->cacheKey($clientIp, $username)) >= $this->maxAttempts) {
            return true;
        }

        return $this->maxAttemptsPerIp > 0 && $this->readCount($this->ipCacheKey($clientIp)) >= $this->maxAttemptsPerIp;
    }

    /**
     * Records a failed login attempt for this IP and username.
     */
    public function registerFailedAttempt(string $clientIp, string $username): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->maxAttempts > 0) {
            $this->increment($this->cacheKey($clientIp, $username));
        }

        if ($this->maxAttemptsPerIp > 0) {
            $this->increment($this->ipCacheKey($clientIp));
        }
    }

    /**
     * Clears the failed-attempt counter for this IP and username after a successful login.
     */
    public function reset(string $clientIp, string $username): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->cache->deleteItem($this->cacheKey($clientIp, $username));
    }

    private function readCount(string $key): int
    {
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return 0;
        }

        $data = $item->get();

        return is_array($data) ? (int) ($data['count'] ?? 0) : 0;
    }

    private function increment(string $key): void
    {
        $item          = $this->cache->getItem($key);
        $data          = $item->isHit() && is_array($item->get()) ? $item->get() : ['count' => 0];
        $data['count'] = (int) ($data['count'] ?? 0) + 1;
        $item->set($data);
        $item->expiresAfter(max(60, $this->intervalSeconds));
        $this->cache->save($item);
    }

    private function cacheKey(string $clientIp, string $username): string
    {
        return self::CACHE_PREFIX . hash('sha256', $clientIp . '|' . mb_strtolower($username));
    }

    private function ipCacheKey(string $clientIp): string
    {
        return self::IP_CACHE_PREFIX . hash('sha256', $clientIp);
    }
}
