<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\VaultItemType;

/**
 * One catalogue row (1.16.0): exactly the metadata a scope member may see without access to the item (contract §5.2).
 * No folder, creator, creation date, secret age, grant, trash state or payload field.
 */
final readonly class VaultCatalogueEntry
{
    /**
     * @param list<string> $tags scope tag names
     * @param DateTimeImmutable $updatedOn date of the last change, time set to 00:00 UTC (Q-O5: no time of day)
     * @param bool $viewerHasAccess the viewer already has View (scope baseline or an active grant), no need to request it
     */
    public function __construct(
        public string $id,
        public string $title,
        public VaultItemType $type,
        public array $tags,
        public string $ownerScope,
        public DateTimeImmutable $updatedOn,
        public bool $viewerHasAccess,
    ) {
    }
}
