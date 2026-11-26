<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\View;

use DateTimeImmutable;

/**
 * Read model of one browser extension token for a host (2.5.0, Beacon spec 185 FR-022): the id and the dates only,
 * never the token or its hash.
 */
final readonly class VaultExtensionTokenView
{
    public function __construct(
        public string $id,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {
    }

    /**
     * Whether the token has expired at that moment (it no longer signs in; the purge command deletes it).
     */
    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $this->expiresAt instanceof DateTimeImmutable && $this->expiresAt <= $now;
    }
}
