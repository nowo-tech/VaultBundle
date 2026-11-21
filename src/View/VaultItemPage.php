<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\View;

use Nowo\VaultBundle\Service\VaultItemQueryInterface;

/**
 * One page of a {@see VaultItemQueryInterface::find()} query (2.1.0).
 */
final readonly class VaultItemPage
{
    /**
     * @param list<VaultItemView> $items
     * @param int $total the number of items matching the criteria, all pages together
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $offset,
        public int $limit,
    ) {
    }

    /**
     * Whether another page follows this one.
     */
    public function hasMore(): bool
    {
        return $this->offset + $this->limit < $this->total;
    }
}
