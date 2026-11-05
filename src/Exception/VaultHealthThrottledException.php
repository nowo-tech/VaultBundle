<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use RuntimeException;

/**
 * A viewer asked for a new health report of the same owner within `health.min_interval_seconds` (SEC-004 FQ-I2): each
 * report decrypts every examined password and may call the breach checker per password. 429 on the route.
 */
final class VaultHealthThrottledException extends RuntimeException
{
    /**
     * Creates the exception with the seconds to wait.
     */
    public function __construct(
        private readonly int $retryAfter,
    ) {
        parent::__construct('Vault health report throttled.');
    }

    /**
     * Seconds until the next report is allowed.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
