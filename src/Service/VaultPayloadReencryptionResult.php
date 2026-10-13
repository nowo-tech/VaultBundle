<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

final readonly class VaultPayloadReencryptionResult
{
    public function __construct(
        public int $totalItems,
        public int $processedItems,
        public bool $dryRun,
        /** Items skipped because they already decrypt with the new key (resumed run). */
        public int $skippedItems = 0,
    ) {
    }
}
