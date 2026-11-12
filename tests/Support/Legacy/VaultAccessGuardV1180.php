<?php

declare(strict_types=1);

// Literal copy of v1.18.0 src/Service/VaultAccessGuard.php (equivalence reference of 1.19.0): only the namespace, the class name and the
// imports differ. Never edit the logic.

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function in_array;

/**
 * Checks vault item/folder access: ownership (personal owner or scope membership, 1.16.0), active grants (expiry
 * enforced on every read against the injected clock), read-only events, and extensible ACL.
 */
final readonly class VaultAccessGuardV1180
{
    /**
     * Creates the guard with the grant repository, team resolver and event dispatcher.
     */
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
        private VaultTeamMembershipResolverInterface $teamMembershipResolver,
        private EventDispatcherInterface $eventDispatcher,
        private ?RequestScopedScopeAccessCacheV1180 $scopeAccess = null,
        private ?ClockInterface $clock = null,
        private ?VaultUserReferenceResolverInterface $users = null,
    ) {
    }

    /**
     * Whether the user may perform the action on the item (ownership, grants and access-check listeners).
     */
    public function canAccessItem(UserInterface $user, VaultItem $item, VaultAccessAction $action): bool
    {
        if ($this->isForeignUser($user)) {
            return false;
        }

        $permission = $this->effectiveItemPermission($user, $item);
        if ($permission === false) {
            // Scope membership gate (1.16.0): a non-member has no access, grants and listeners included.
            return false;
        }
        $granted = $this->actionAllowed($permission, $action);

        $event = new VaultItemAccessCheckEvent($user, $item, $action, $granted);
        $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_ACCESS_CHECK);

        if (!$event->isGranted()) {
            return false;
        }

        return $action->isViewOnly() || !$event->isReadOnly() && !$this->isItemReadOnly($user, $item)

        ;
    }

    /**
     * Whether the user may perform the action on the folder (ownership, grants and access-check listeners).
     */
    public function canAccessFolder(UserInterface $user, VaultFolder $folder, VaultAccessAction $action): bool
    {
        if ($this->isForeignUser($user)) {
            return false;
        }

        $permission = $this->effectiveFolderPermission($user, $folder);
        if ($permission === false) {
            return false;
        }
        $granted = $this->actionAllowed($permission, $action);

        $event = new VaultFolderAccessCheckEvent($user, $folder, $action, $granted);
        $this->eventDispatcher->dispatch($event, VaultEvents::FOLDER_ACCESS_CHECK);

        return $event->isGranted();
    }

    /**
     * Whether listeners mark the item read-only for the user.
     */
    public function isItemReadOnly(UserInterface $user, VaultItem $item): bool
    {
        if (UserIdResolver::getId($user) === null) {
            return false;
        }

        $event = new VaultItemReadOnlyEvent($user, $item);
        $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_READ_ONLY_RESOLVE);

        return $event->isReadOnly();
    }

    /**
     * Returns the read-only flag per item id for the user.
     *
     * @param list<VaultItem> $items
     *
     * @return array<string, bool> Map of item id => read-only
     */
    public function resolveReadOnlyMap(UserInterface $user, array $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item->getId()] = $this->isItemReadOnly($user, $item);
        }

        return $map;
    }

    /**
     * Effective permission of the user on each item before the access-check events (1.16.0, SC-004): a constant
     * number of queries for the whole list (one grant query; scope membership memoized per request). The ITEM_ACCESS_CHECK
     * event is then applied for View: a listener veto maps the item to null, a listener grant to at least Read.
     *
     * @param list<VaultItem> $items
     *
     * @return array<string, VaultPermission|null> item id => effective permission (null = no access)
     */
    public function resolvePermissionMap(UserInterface $user, array $items): array
    {
        if ($this->isForeignUser($user)) {
            return array_fill_keys(array_map(static fn (VaultItem $item): string => $item->getId(), $items), null);
        }

        $grants = $this->loadGrants($user, $items);
        $map    = [];
        foreach ($items as $item) {
            $permission = $this->effectiveItemPermission($user, $item, $grants);
            if ($permission === false) {
                $map[$item->getId()] = null;

                continue;
            }

            $event = new VaultItemAccessCheckEvent($user, $item, VaultAccessAction::View, $permission instanceof VaultPermission);
            $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_ACCESS_CHECK);
            $map[$item->getId()] = $event->isGranted() ? ($permission ?? VaultPermission::Read) : null;
        }

        return $map;
    }

    /**
     * Whether the user can View each scope item, from ids only (1.16.0, the catalogue's `viewerHasAccess`): the scope
     * baseline, or an active grant on the item or on its folder (same scope). No entity is loaded and the
     * ITEM_ACCESS_CHECK listeners are not consulted: it is a hint for the catalogue; every item route re-checks with
     * {@see self::canAccessItem()}.
     *
     * @param array<string, string|null> $itemFolders item id => id of its folder when that folder belongs to the scope
     *
     * @return array<string, bool>
     */
    public function resolveScopeViewMap(UserInterface $user, string $scope, array $itemFolders): array
    {
        $map    = array_fill_keys(array_keys($itemFolders), false);
        $access = $this->resolveScopeAccess($user, $scope);
        if (!$access instanceof VaultScopeAccess || $itemFolders === []) {
            return $map;
        }

        if ($access->baseline instanceof VaultPermission) {
            return array_fill_keys(array_keys($itemFolders), true);
        }

        $userId = UserIdResolver::getId($user);
        if ($userId === null) {
            return $map;
        }

        $itemIds   = array_map(strval(...), array_keys($itemFolders));
        $folderIds = array_values(array_unique(array_filter($itemFolders, static fn (?string $id): bool => $id !== null)));
        $now       = $this->now();
        $teamIds   = $this->teamMembershipResolver->getTeamIdsForUser($user);
        $grants    = [];
        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            $grants = $this->grantRepository->findActiveForGrantees($itemIds, $folderIds, $userId, $teamIds, $now);
        } else {
            foreach ($itemIds as $itemId) {
                $grants = [...$grants, ...$this->legacyGrants($userId, $teamIds, VaultResourceType::Item, $itemId)];
            }
            foreach ($folderIds as $folderId) {
                $grants = [...$grants, ...$this->legacyGrants($userId, $teamIds, VaultResourceType::Folder, $folderId)];
            }
        }

        $granted = [];
        foreach ($grants as $grant) {
            if ($grant->isActiveAt($now)) {
                $granted[$grant->getResourceType()->value . ':' . $grant->getResourceId()] = true;
            }
        }
        foreach ($itemFolders as $itemId => $folderId) {
            $map[$itemId] = isset($granted['item:' . $itemId]) || ($folderId !== null && isset($granted['folder:' . $folderId]));
        }

        return $map;
    }

    /**
     * The user's standing in a scope for this request (null: not a member, or scopes are off).
     */
    public function resolveScopeAccess(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        if ($this->isForeignUser($user)) {
            return null;
        }

        return $this->scopeAccess?->resolve($user, $scope);
    }

    /**
     * 1.17.0 (LQ-V-L1): a user the vault does not accept (neither `user_class` nor listed in `accepted_user_classes`)
     * owns nothing, holds no grant and is in no scope, whatever its id: Admin#5 never matches Customer#5, also for
     * host code that calls the guard directly.
     */
    private function isForeignUser(UserInterface $user): bool
    {
        return $this->users instanceof VaultUserReferenceResolverInterface && !$this->users->isAccepted($user);
    }

    /**
     * Effective permission on a folder: owner Admin, scope baseline (false for a non-member), active folder grants.
     */
    private function effectiveFolderPermission(UserInterface $user, VaultFolder $folder): VaultPermission|false|null
    {
        $owner = $folder->getOwner();
        if (!$owner->isScope()) {
            if ($owner->isUser($user)) {
                return VaultPermission::Admin;
            }
            $permission = null;
        } else {
            $access = $this->resolveScopeAccess($user, (string) $owner->getScope());
            if (!$access instanceof VaultScopeAccess) {
                // Membership gate: grants never cross it.
                return false;
            }
            $permission = $access->baseline;
        }

        foreach ($this->activeGrants($user, VaultResourceType::Folder, $folder->getId()) as $grant) {
            $permission = $this->maxPermission($permission, $grant->getPermission());
        }

        return $permission;
    }

    /**
     * Effective permission on one item (§3.2 of the 1.16.0 contract): the personal owner is Admin; a scope item
     * starts from the member's baseline (a non-member is denied, grants included: `false`); then the maximum of the
     * active grants on the item and, when the folder has the same owner as the item (SEC-004 M1), on its folder.
     *
     * @param list<VaultGrant>|null $grants preloaded active grants of the user (and teams) on the items and their folders; null = load for this item
     *
     * @return false|VaultPermission|null false = membership gate denied
     */
    private function effectiveItemPermission(UserInterface $user, VaultItem $item, ?array $grants = null): VaultPermission|false|null
    {
        $owner = $item->getOwner();
        if (!$owner->isScope()) {
            if ($owner->isUser($user)) {
                return VaultPermission::Admin;
            }
            $permission = null;
        } else {
            $access = $this->resolveScopeAccess($user, (string) $owner->getScope());
            if (!$access instanceof VaultScopeAccess) {
                return false;
            }
            $permission = $access->baseline;
        }

        // Folder grants cover only items with the folder's owner: otherwise a Write grantee could move someone else's
        // item into a folder they own and grant themselves Admin on it through the folder (SEC-004 M1).
        $folder   = $item->getFolder();
        $folderId = $folder instanceof VaultFolder && $folder->getOwner()->equals($owner) ? $folder->getId() : null;

        foreach ($grants ?? $this->loadGrants($user, [$item]) as $grant) {
            $type = $grant->getResourceType();
            $id   = $grant->getResourceId();
            if (($type === VaultResourceType::Item && $id === $item->getId()) || ($folderId !== null && $type === VaultResourceType::Folder && $id === $folderId)) {
                $permission = $this->maxPermission($permission, $grant->getPermission());
            }
        }

        return $permission;
    }

    /**
     * Active grants of the user (directly or through a team) on the items and their folders: one query with the
     * 1.16.0 Doctrine repository, else the {@see VaultGrantRepositoryInterface} lookups of earlier releases. Either way
     * every grant is re-checked with {@see VaultGrant::isActiveAt()} against the injected clock.
     *
     * @param list<VaultItem> $items
     *
     * @return list<VaultGrant>
     */
    private function loadGrants(UserInterface $user, array $items): array
    {
        $userId = UserIdResolver::getId($user);
        if ($userId === null || $items === []) {
            return [];
        }

        $itemIds   = [];
        $folderIds = [];
        foreach ($items as $item) {
            $itemIds[] = $item->getId();
            if ($item->getFolder() instanceof VaultFolder) {
                $folderIds[$item->getFolder()->getId()] = true;
            }
        }

        $now     = $this->now();
        $teamIds = $this->teamMembershipResolver->getTeamIdsForUser($user);
        if ($this->grantRepository instanceof VaultGrantExpiryRepositoryInterface) {
            $grants = $this->grantRepository->findActiveForGrantees($itemIds, array_keys($folderIds), $userId, $teamIds, $now);
        } else {
            $grants = [];
            foreach ($itemIds as $itemId) {
                $grants = [...$grants, ...$this->legacyGrants($userId, $teamIds, VaultResourceType::Item, $itemId)];
            }
            foreach (array_keys($folderIds) as $folderId) {
                $grants = [...$grants, ...$this->legacyGrants($userId, $teamIds, VaultResourceType::Folder, (string) $folderId)];
            }
        }

        return array_values(array_filter($grants, static fn (VaultGrant $grant): bool => $grant->isActiveAt($now)));
    }

    /**
     * Active grants of the user (or teams) on one resource.
     *
     * @return list<VaultGrant>
     */
    private function activeGrants(UserInterface $user, VaultResourceType $type, string $resourceId): array
    {
        $userId = UserIdResolver::getId($user);
        if ($userId === null) {
            return [];
        }

        $now     = $this->now();
        $teamIds = $this->teamMembershipResolver->getTeamIdsForUser($user);
        $grants  = $this->grantRepository instanceof VaultGrantExpiryRepositoryInterface
            ? $this->grantRepository->findActiveForGrantees($type === VaultResourceType::Item ? [$resourceId] : [], $type === VaultResourceType::Folder ? [$resourceId] : [], $userId, $teamIds, $now)
            : $this->legacyGrants($userId, $teamIds, $type, $resourceId);

        return array_values(array_filter($grants, static fn (VaultGrant $grant): bool => $grant->isActiveAt($now)));
    }

    /**
     * Pre-1.16 lookups for a custom {@see VaultGrantRepositoryInterface}: the resource's grants matching the user or a
     * team, plus the user's grants on the resource.
     *
     * @param list<string> $teamIds
     *
     * @return list<VaultGrant>
     */
    private function legacyGrants(string $userId, array $teamIds, VaultResourceType $type, string $resourceId): array
    {
        $matches = [];
        foreach ($this->grantRepository->findByResource($type, $resourceId) as $grant) {
            if (($grant->getGranteeType() === GranteeType::User && $grant->getGranteeId() === $userId)
                || ($grant->getGranteeType() === GranteeType::Team && in_array($grant->getGranteeId(), $teamIds, true))) {
                $matches[] = $grant;
            }
        }

        foreach ($this->grantRepository->findByGrantee(GranteeType::User, $userId) as $grant) {
            if ($grant->getResourceType() === $type && $grant->getResourceId() === $resourceId) {
                $matches[] = $grant;
            }
        }

        return $matches;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function maxPermission(?VaultPermission $current, VaultPermission $candidate): VaultPermission
    {
        if (!$current instanceof VaultPermission) {
            return $candidate;
        }

        $order = [VaultPermission::Read->value => 1, VaultPermission::Write->value => 2, VaultPermission::Admin->value => 3];

        return $order[$candidate->value] >= $order[$current->value] ? $candidate : $current;
    }

    private function actionAllowed(?VaultPermission $permission, VaultAccessAction $action): bool
    {
        if (!$permission instanceof VaultPermission) {
            return false;
        }

        return match ($action) {
            VaultAccessAction::View                                                       => true,
            VaultAccessAction::Edit, VaultAccessAction::Restore                           => $permission->allowsWrite(),
            VaultAccessAction::Delete, VaultAccessAction::Share, VaultAccessAction::Purge => $permission->allowsAdmin(),
        };
    }
}
