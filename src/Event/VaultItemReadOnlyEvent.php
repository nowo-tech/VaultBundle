<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired to decide whether an item is read-only for the current user.
 *
 * Listeners call {@see markReadOnly()} to restrict the user to view-only access
 * (no edit, trash, share, restore, or purge) even when grants would allow writes.
 */
final class VaultItemReadOnlyEvent extends Event
{
    private bool $readOnly = false;

    /**
     * Creates the event for a user and item.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultItem $item,
    ) {
    }

    /**
     * Returns the user being checked.
     *
     * @deprecated since 1.19.0, use getUserRef() / getUserId() (no Symfony type, REQ-ARCH-001 rule 3); removed in 2.0
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * Returns the item being checked.
     */
    public function getItem(): VaultItem
    {
        return $this->item;
    }

    /**
     * Whether the item must be shown read-only.
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    /**
     * Marks the item read-only.
     */
    public function markReadOnly(): void
    {
        $this->readOnly = true;
    }

    /**
     * Clears the read-only flag.
     */
    public function clearReadOnly(): void
    {
        $this->readOnly = false;
    }

    /**
     * The user as a framework-free reference (1.19.0, REQ-ARCH-001 rules 3 and 4).
     */
    public function getUserRef(): VaultUserRef
    {
        return VaultUserRefs::fromUser($this->user);
    }

    /**
     * The user's vault user id, or null when it has none (1.19.0).
     */
    public function getUserId(): ?string
    {
        return $this->getUserRef()->id;
    }

    /**
     * Id of the item (1.19.0, REQ-ARCH-001 rule 4).
     */
    public function getItemId(): string
    {
        return $this->item->getId();
    }

    /**
     * Owner key of the item: `user:<id>` or `scope:<key>` (1.19.0).
     */
    public function getOwnerKey(): string
    {
        return $this->item->getOwner()->key();
    }
}
