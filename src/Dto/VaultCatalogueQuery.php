<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use Nowo\VaultBundle\Enum\VaultItemType;

/**
 * Filters and page of a catalogue request (1.16.0).
 */
final readonly class VaultCatalogueQuery
{
    /**
     * @param string|null $search case-insensitive title search, used when 2 to 100 characters long (wildcards are literal)
     * @param string|null $tagId a scope tag id
     */
    public function __construct(
        public ?string $search = null,
        public ?VaultItemType $type = null,
        public ?string $tagId = null,
        public int $page = 1,
        public int $perPage = 50,
    ) {
    }
}
