<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
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
     *
     * @deprecated since 1.19.0, use getUserRef() / getUserId() (no Symfony type, REQ-ARCH-001 rule 3); removed in 2.0
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
     * Id of the folder (1.19.0, REQ-ARCH-001 rule 4).
     */
    public function getFolderId(): string
    {
        return $this->folder->getId();
    }

    /**
     * Owner key of the folder: `user:<id>` or `scope:<key>` (1.19.0).
     */
    public function getOwnerKey(): string
    {
        return $this->folder->getOwner()->key();
    }
}
