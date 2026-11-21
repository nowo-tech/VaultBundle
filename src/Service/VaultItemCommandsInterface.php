<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use InvalidArgumentException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultItemChangedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultItemView;

/**
 * Item writes for a host (2.1.0, DDD-host checklist B1, REQ-ARCH-001 rules 1 and 3): the actor is a
 * {@see VaultUserRef}, resources are ids, and every call returns a {@see VaultItemView} (never an entity). Implemented
 * by {@see VaultItemCommands}.
 *
 * Each call re-checks the actor's access at the call (as the vault pages do), runs in one vault write unit (one
 * transaction; a savepoint inside the application's transaction) and emits the usual change events, held until the
 * commit. A reference by id only is looked up again through the user directory port (audit NJ-1).
 *
 * Errors: {@see VaultAccessDeniedException} when the actor is unknown or not accepted, the resource does not exist or
 * the actor may not do this (one answer for all three, so an id never confirms that a resource exists);
 * {@see VaultOwnerException} for a folder of another owner; {@see InvalidArgumentException} for a blank or too long
 * title; {@see TransientStorageFailure} for a lock conflict (retry).
 */
interface VaultItemCommandsInterface
{
    /** Longest item title. */
    public const MAX_TITLE_LENGTH = 255;

    /**
     * Creates a personal item of the actor, encrypted, optionally in one of the actor's folders and with tags.
     *
     * @param array<string, mixed> $payload the item fields (the keys of the type's form)
     * @param list<string> $tagNames tag names (normalised; missing tags are created)
     */
    public function create(VaultUserRef $actor, VaultItemType $type, string $title, array $payload, ?string $folderId = null, array $tagNames = []): VaultItemView;

    /**
     * Creates an item owned by a scope; the actor needs `create` in the scope and the folder must belong to it.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $tagNames
     */
    public function createInScope(VaultUserRef $actor, string $scope, VaultItemType $type, string $title, array $payload, ?string $folderId = null, array $tagNames = []): VaultItemView;

    /**
     * Replaces the title and the payload of an active item (Edit; a read-only item is refused). The payload replaces
     * the stored one as a whole.
     *
     * With `$expectedEditToken` (the {@see VaultItemView::$editToken} the caller read), a save is refused with
     * {@see VaultItemChangedException} when someone saved the item meanwhile (REQ-RUNTIME-003 rule 4); the row is
     * locked for the check and the write.
     *
     * @param array<string, mixed> $payload
     */
    public function update(VaultUserRef $actor, string $itemId, string $title, array $payload, ?string $expectedEditToken = null): VaultItemView;

    /**
     * Moves an active item to another folder of its owner (null: no folder). Only the owner may organise: the personal
     * owner, or a scope member who may Share the item.
     */
    public function move(VaultUserRef $actor, string $itemId, ?string $folderId): VaultItemView;

    /**
     * Replaces the tags of an active item (tags of the item's owner; missing ones are created). Same rights as
     * {@see self::move()}.
     *
     * @param list<string> $tagNames
     */
    public function retag(VaultUserRef $actor, string $itemId, array $tagNames): VaultItemView;

    /**
     * Moves an active item to the trash (Delete).
     */
    public function moveToTrash(VaultUserRef $actor, string $itemId): VaultItemView;

    /**
     * Restores a trashed item (Restore).
     */
    public function restore(VaultUserRef $actor, string $itemId): VaultItemView;

    /**
     * Deletes an item and its grants permanently (Purge).
     */
    public function purge(VaultUserRef $actor, string $itemId): void;
}
