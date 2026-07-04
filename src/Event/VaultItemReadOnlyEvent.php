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

    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultItem $item,
    ) {
    }

    public function getUser(): UserInterface
    {
        return $this->user;
    }

    public function getItem(): VaultItem
    {
        return $this->item;
    }

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }

    public function markReadOnly(): void
    {
        $this->readOnly = true;
    }

    public function clearReadOnly(): void
    {
        $this->readOnly = false;
    }
}
