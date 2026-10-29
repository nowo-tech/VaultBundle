<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

/**
 * Outcome of a payload re-encryption run.
 */
final readonly class VaultPayloadReencryptionResult
{
    /**
     * Creates the result with total, processed and skipped item counts and the dry-run flag.
     */
    public function __construct(
        public int $totalItems,
        public int $processedItems,
        public bool $dryRun,
        /** Items skipped because they already decrypt with the new key (resumed run). */
        public int $skippedItems = 0,
    ) {
    }
}
