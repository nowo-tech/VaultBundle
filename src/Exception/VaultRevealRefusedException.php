<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use RuntimeException;

/**
 * A field reveal, copy or attachment download was refused (1.16.0). Carries the reason and, for a throttled request,
 * the exhausted window and the Retry-After seconds. Never a value.
 */
final class VaultRevealRefusedException extends RuntimeException
{
    /** Unknown field, field not revealable for the type, vetoed by ITEM_FIELD_REVEAL_CHECK, or no attachment (404). */
    public const NOT_REVEALABLE = 'not_revealable';

    /** `reveal.rate_limit` or `security.secret_access_rate_limit` exhausted (429). */
    public const THROTTLED = 'throttled';

    /** An audit listener threw: no audit, no reveal (503). */
    public const AUDIT_FAILED = 'audit_failed';

    /**
     * Creates the exception.
     */
    public function __construct(
        private readonly string $reason,
        private readonly ?string $window = null,
        private readonly int $retryAfter = 0,
    ) {
        parent::__construct('Vault reveal refused: ' . $reason . '.');
    }

    /**
     * NOT_REVEALABLE, THROTTLED or AUDIT_FAILED.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * The exhausted window of a throttled request, else null.
     */
    public function getWindow(): ?string
    {
        return $this->window;
    }

    /**
     * Retry-After seconds of a throttled request (0 otherwise).
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
