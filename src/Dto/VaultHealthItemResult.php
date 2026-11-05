<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

/**
 * Health of one login item (1.16.0): flags only, never the password, a fingerprint or a hash.
 */
final readonly class VaultHealthItemResult
{
    /**
     * @param list<string> $weakReasons e.g. `min_length`, `min_classes`, `empty`, or rules of the strength evaluator
     * @param int|null $reuseGroup small integer local to this report (1, 2, …) shared by items with the same password; null = unique
     * @param int|null $ageDays days since the last secret change (null when unknown)
     * @param bool|null $breached null when no breach checker is configured or it could not tell
     */
    public function __construct(
        public string $itemId,
        public string $title,
        public bool $weak,
        public array $weakReasons,
        public ?int $reuseGroup,
        public bool $old,
        public ?int $ageDays,
        public ?bool $breached,
    ) {
    }
}
