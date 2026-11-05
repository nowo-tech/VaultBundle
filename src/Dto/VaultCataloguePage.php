<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use function ceil;
use function max;

/**
 * One page of a scope catalogue (1.16.0).
 */
final readonly class VaultCataloguePage
{
    /**
     * @param list<VaultCatalogueEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /**
     * Number of pages (at least 1).
     */
    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
