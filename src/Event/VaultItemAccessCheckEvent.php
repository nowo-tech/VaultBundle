<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

final class VaultItemAccessCheckEvent extends Event
{
    private bool $readOnly = false;

    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultItem $item,
        private readonly VaultAccessAction $action,
        private bool $granted,
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

    public function getAction(): VaultAccessAction
    {
        return $this->action;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function grant(): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->granted = true;
    }

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

    public function isReadOnly(): bool
    {
        return $this->readOnly;
    }
}
