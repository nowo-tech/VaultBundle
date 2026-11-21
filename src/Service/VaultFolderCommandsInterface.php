<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultFolderView;

/**
 * Folder writes for a host (2.1.0, DDD-host checklist B1, REQ-ARCH-001 rules 1 and 3): actor as a {@see VaultUserRef},
 * folders by id, {@see VaultFolderView} results. Implemented by {@see VaultFolderCommands}. Same access, transaction,
 * event and error rules as {@see VaultItemCommandsInterface} ({@see VaultAccessDeniedException},
 * {@see VaultOwnerException} for a parent of another owner, {@see InvalidArgumentException} for a blank or too long
 * name).
 */
interface VaultFolderCommandsInterface
{
    /** Longest folder name. */
    public const MAX_NAME_LENGTH = 255;

    /**
     * Creates a personal folder of the actor, optionally inside one of the actor's active folders.
     */
    public function create(VaultUserRef $actor, string $name, ?string $parentId = null): VaultFolderView;

    /**
     * Creates a folder owned by a scope; the actor needs `create` in the scope and the parent must belong to it.
     */
    public function createInScope(VaultUserRef $actor, string $scope, string $name, ?string $parentId = null): VaultFolderView;

    /**
     * Renames an active folder: its owner's organisation (the personal owner, or a scope member who may Share the
     * folder; a Write grantee may not).
     */
    public function rename(VaultUserRef $actor, string $folderId, string $name): VaultFolderView;

    /**
     * Moves an active folder to the trash (Delete); its active items leave the folder. Returns how many did.
     */
    public function moveToTrash(VaultUserRef $actor, string $folderId): int;

    /**
     * Restores a trashed folder (Restore).
     */
    public function restore(VaultUserRef $actor, string $folderId): VaultFolderView;

    /**
     * Deletes a folder and its grants permanently (Purge).
     */
    public function purge(VaultUserRef $actor, string $folderId): void;
}
