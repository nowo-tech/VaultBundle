<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use DateTimeImmutable;
use Nowo\VaultBundle\Event\VaultRevealThrottledEvent;
use Nowo\VaultBundle\Support\UserIdResolver;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash;
use function is_array;
use function is_int;
use function max;

/**
 * Per-user limits of the web reveal, copy and attachment routes (1.16.0, `reveal.rate_limit`): requests per user per
 * minute, per user per hour, and per user, item and field per minute. Fixed windows in a PSR-6 pool (same pattern as
 * the extension login limiter); best effort under concurrent requests. Stateless service: counters live in the pool.
 */
final readonly class VaultRevealRateLimiter
{
    private const PREFIX = 'nowo_vault_reveal_';

    /**
     * Stores the pool, the three limits and the switch.
     */
    public function __construct(
        private CacheItemPoolInterface $cache,
        private int $perUserPerMinute = 30,
        private int $perUserPerHour = 300,
        private int $perUserItemFieldPerMinute = 10,
        private bool $enabled = true,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Counts one request and returns null, or returns the exhausted window and the seconds until it resets (nothing is
     * counted then).
     *
     * @return array{window: string, retryAfter: int}|null
     */
    public function consume(UserInterface $user, string $itemId, string $field): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $now     = ($this->clock?->now() ?? new DateTimeImmutable())->getTimestamp();
        $userKey = UserIdResolver::getId($user) ?? 'identifier|' . $user->getUserIdentifier();
        $windows = [
            VaultRevealThrottledEvent::WINDOW_MINUTE => ['u|' . $userKey . '|m', 60, $this->perUserPerMinute],
            VaultRevealThrottledEvent::WINDOW_HOUR   => ['u|' . $userKey . '|h', 3600, $this->perUserPerHour],
            VaultRevealThrottledEvent::WINDOW_FIELD  => ['f|' . $userKey . '|' . $itemId . '|' . $field, 60, $this->perUserItemFieldPerMinute],
        ];

        $states = [];
        foreach ($windows as $window => [$key, $seconds, $max]) {
            $item = $this->cache->getItem(self::PREFIX . hash('sha256', $key));
            $data = $item->isHit() ? $item->get() : null;
            if (!is_array($data) || !is_int($data['count'] ?? null) || !is_int($data['reset'] ?? null) || $data['reset'] <= $now) {
                $data = ['count' => 0, 'reset' => $now + $seconds];
            }
            if ($data['count'] >= $max) {
                return ['window' => $window, 'retryAfter' => (int) max(1, $data['reset'] - $now)];
            }
            $states[] = [$item, $data];
        }

        foreach ($states as [$item, $data]) {
            ++$data['count'];
            $item->set($data);
            $item->expiresAt(new DateTimeImmutable('@' . $data['reset']));
            $this->cache->save($item);
        }

        return null;
    }
}
