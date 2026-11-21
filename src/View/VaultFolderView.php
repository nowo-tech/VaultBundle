<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\View;

use DateTimeImmutable;

/**
 * Read model of one vault folder for a host (2.1.0, REQ-ARCH-001 rules 1 and 3): ids, scalars and dates only.
 */
final readonly class VaultFolderView
{
    /**
     * @param string $ownerKey `user:<id>` or `scope:<key>`
     * @param string|null $ownerUserId the personal owner's id (null for a scope folder)
     * @param string|null $ownerScope the owning scope (null for a personal folder)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $ownerKey,
        public ?string $ownerUserId,
        public ?string $ownerScope,
        public ?string $parentId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
    }

    /**
     * Whether the folder is in the trash.
     */
    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }
}
