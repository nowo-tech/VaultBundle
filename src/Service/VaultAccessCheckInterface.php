<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Access checks by id for a host (2.1.0, DDD-host checklist B8, REQ-ARCH-001 rules 1 and 3): the same decisions as the
 * vault pages (ownership, scope membership, active grants, the access-check and read-only events) for a user given as a
 * {@see VaultUserRef} and a resource given by id. Implemented by {@see VaultIdAccessChecker}.
 *
 * A reference by id only is looked up again through the user directory port (audit NJ-1); an unknown user, an unknown
 * resource or a user the vault does not accept gets `false` / null, never an exception (an id never confirms that a
 * resource exists).
 */
interface VaultAccessCheckInterface
{
    /**
     * Whether the user may perform the action on the item. A trashed item is only reachable for Restore and Purge.
     */
    public function canAccessItem(VaultUserRef $user, string $itemId, VaultAccessAction $action): bool;

    /**
     * Whether the user may perform the action on the folder. A trashed folder is only reachable for Restore and Purge.
     */
    public function canAccessFolder(VaultUserRef $user, string $folderId, VaultAccessAction $action): bool;

    /**
     * Whether listeners mark the (active) item read-only for the user; false for an unknown item or user.
     */
    public function isItemReadOnly(VaultUserRef $user, string $itemId): bool;

    /**
     * The effective permission of the user on each item, in one pass: every requested id (duplicates once, at most
     * 500) is a key, and null means no access, an unknown or trashed item, or an unknown user alike. The
     * ITEM_ACCESS_CHECK listeners are applied for View.
     *
     * @param list<string> $itemIds
     *
     * @return array<string, VaultPermission|null> item id => permission
     */
    public function itemPermissions(VaultUserRef $user, array $itemIds): array;

    /**
     * The user's standing in a scope for this request (null: not a member, scopes off, or an unknown user).
     */
    public function scopeAccess(VaultUserRef $user, string $scope): ?VaultScopeAccess;
}
