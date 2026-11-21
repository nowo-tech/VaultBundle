<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\View;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCommandsInterface;

/**
 * Read model of one vault item for a host (2.1.0, REQ-ARCH-001 rules 1 and 3): ids, scalars and dates only. It never
 * carries a payload value, the ciphertext or a hash of a secret, and no Doctrine, Symfony or entity type.
 *
 * `editToken` is the item's edit token as it is now (the one the edit page carries): pass it back to
 * {@see VaultItemCommandsInterface::update()} to refuse a save that would overwrite a change
 * saved meanwhile (REQ-RUNTIME-003 rule 4).
 */
final readonly class VaultItemView
{
    /**
     * @param string $ownerKey `user:<id>` or `scope:<key>` (the key of the change events)
     * @param string|null $ownerUserId the personal owner's id (null for a scope item, or once that user was deleted)
     * @param string|null $ownerScope the owning scope (null for a personal item)
     * @param list<string> $tagNames the item's tag names, sorted
     * @param string|null $createdById who created the item (audit only; null once that user was deleted)
     */
    public function __construct(
        public string $id,
        public VaultItemType $type,
        public string $title,
        public string $ownerKey,
        public ?string $ownerUserId,
        public ?string $ownerScope,
        public ?string $folderId,
        public array $tagNames,
        public ?string $createdById,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $secretChangedAt,
        public ?DateTimeImmutable $deletedAt,
        public bool $catalogueHidden,
        public string $editToken,
    ) {
    }

    /**
     * Whether the item is in the trash.
     */
    public function isTrashed(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }
}
