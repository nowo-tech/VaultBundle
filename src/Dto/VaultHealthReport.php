<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use DateTimeImmutable;
use Nowo\VaultBundle\ValueObject\VaultOwner;

use function count;

/**
 * Password health of one owner (a scope or a personal vault) as seen by one viewer (1.16.0). Computed on demand in
 * memory: nothing in it can reveal a password (no value, no fingerprint, no ciphertext).
 */
final readonly class VaultHealthReport
{
    /**
     * @param int $examined login items whose password was checked
     * @param int $skipped login items left out (no View access, or not decryptable)
     * @param list<VaultHealthItemResult> $items
     */
    public function __construct(
        public VaultOwner $owner,
        public DateTimeImmutable $generatedAt,
        public int $examined,
        public int $skipped,
        public array $items,
    ) {
    }

    /**
     * Number of weak passwords.
     */
    public function weakCount(): int
    {
        return count(array_filter($this->items, static fn (VaultHealthItemResult $r): bool => $r->weak));
    }

    /**
     * Number of items sharing a password with another examined item.
     */
    public function reusedCount(): int
    {
        return count(array_filter($this->items, static fn (VaultHealthItemResult $r): bool => $r->reuseGroup !== null));
    }

    /**
     * Number of old passwords.
     */
    public function oldCount(): int
    {
        return count(array_filter($this->items, static fn (VaultHealthItemResult $r): bool => $r->old));
    }

    /**
     * Number of passwords the breach checker reported as breached.
     */
    public function breachedCount(): int
    {
        return count(array_filter($this->items, static fn (VaultHealthItemResult $r): bool => $r->breached === true));
    }
}
