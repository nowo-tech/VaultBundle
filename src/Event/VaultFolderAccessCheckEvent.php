<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultFolder;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched to let listeners grant or deny an action on a vault folder.
 */
final class VaultFolderAccessCheckEvent extends Event
{
    /**
     * Creates the event for a user, folder, action and initial decision.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultFolder $folder,
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
     * Returns the folder being accessed.
     */
    public function getFolder(): VaultFolder
    {
        return $this->folder;
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
}
