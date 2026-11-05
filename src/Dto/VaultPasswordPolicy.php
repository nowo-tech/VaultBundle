<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Password policy of an owner (1.16.0): drives the health report ("weak", "old") and, with `enforceOnSave`, the
 * item form.
 */
final readonly class VaultPasswordPolicy
{
    /**
     * @param int $minLength minimum number of characters
     * @param int $minClasses minimum number of character classes (lowercase, uppercase, digit, symbol)
     * @param int|null $maxAgeDays a password older than this is "old" (null: never)
     * @param bool $enforceOnSave validate login passwords typed in the item form
     */
    public function __construct(
        public int $minLength = 12,
        public int $minClasses = 3,
        public ?int $maxAgeDays = 365,
        public bool $enforceOnSave = false,
    ) {
    }

    /**
     * Stable hash of the policy (part of the health cache key).
     */
    public function hash(): string
    {
        return hash('sha256', json_encode([$this->minLength, $this->minClasses, $this->maxAgeDays, $this->enforceOnSave], JSON_THROW_ON_ERROR));
    }
}
