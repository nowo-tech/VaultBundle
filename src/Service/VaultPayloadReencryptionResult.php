<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

final readonly class VaultPayloadReencryptionResult
{
    public function __construct(
        public int $totalItems,
        public int $processedItems,
        public bool $dryRun,
    ) {
    }
}
