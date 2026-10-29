<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
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
}
