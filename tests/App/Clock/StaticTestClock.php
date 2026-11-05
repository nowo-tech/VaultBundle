<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Test clock whose time survives kernel reboots between test requests (a static instant, unlike MockClock, which a
 * rebooted container creates again at its initial time).
 */
final class StaticTestClock implements ClockInterface
{
    private static ?DateTimeImmutable $now = null;

    public function now(): DateTimeImmutable
    {
        // The real time, to the second: PSR-6 pools compare expiry dates with the real clock.
        return self::$now ??= (new DateTimeImmutable('@' . time()))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Moves the shared instant (e.g. '+1 hour').
     */
    public function modify(string $modifier): void
    {
        self::$now = $this->now()->modify($modifier);
    }

    /**
     * Back to the initial instant (test setUp).
     */
    public static function reset(): void
    {
        self::$now = null;
    }
}
