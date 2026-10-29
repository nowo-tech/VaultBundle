<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched to let listeners grant or deny an action on a vault item.
 */
final class VaultItemAccessCheckEvent extends Event
{
    private bool $readOnly = false;

    /**
     * Creates the event for a user, item, action and initial decision.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultItem $item,
        private readonly VaultAccessAction $action,
        private bool $granted,
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
     * Returns the item being accessed.
     */
    public function getItem(): VaultItem
    {
        return $this->item;
    }

    /**
     * Returns the action being checked.
     */
    public function getAction(): VaultAccessAction
    {
        return $this->action;
    }

    /**
     * Whether access is currently granted.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }

    /**
     * Grants access.
     */
    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
    }

    /**
     * Denies access.
     */
    public function deny(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = false;
    }

    /**
     * Restrict this user to view-only for the item (blocks edit/trash/share actions).
     */
    public function markReadOnly(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->readOnly = true;
    }

    /**
     * Whether the item must be shown read-only.
     */
    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
