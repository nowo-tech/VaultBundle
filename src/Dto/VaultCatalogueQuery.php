<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use Nowo\VaultBundle\Enum\VaultItemType;

/**
 * Filters and page of a catalogue request (1.16.0).
 */
final readonly class VaultCatalogueQuery
{
    /** Longest owner id the filter accepts (REQ-SEC-006 cap). */
    public const MAX_OWNER_ID_LENGTH = 128;

    /**
     * @param string|null $search case-insensitive title search, used when 2 to 100 characters long (wildcards are literal)
     * @param string|null $tagId a scope tag id
     * @param string|null $ownerId 2.5.0 (Beacon spec 185 FR-011): only the items this user created (the vault user id,
     *                             the string form of the host user's `getId()`, compared exactly; at most
     *                             {@see self::MAX_OWNER_ID_LENGTH} characters, else nothing matches). Items of an erased
     *                             creator have none and never match.
     */
    public function __construct(
        public ?string $search = null,
        public ?VaultItemType $type = null,
        public ?string $tagId = null,
        public int $page = 1,
        public int $perPage = 50,
        public ?string $ownerId = null,
    ) {
    }
}
