<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemReadOnlyEvent;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_key_exists;
use function in_array;

/**
 * Checks vault item/folder access: creator ownership, grants, read-only events, and extensible ACL.
 */
final class VaultAccessGuard
{
    /** @var array<string, bool> */
    private array $readOnlyCache = [];

    public function __construct(
        private readonly VaultGrantRepositoryInterface $grantRepository,
        private readonly VaultTeamMembershipResolverInterface $teamMembershipResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function canAccessItem(UserInterface $user, VaultItem $item, VaultAccessAction $action): bool
    {
        $granted = $this->baseItemGrant($user, $item, $action);

        $event = new VaultItemAccessCheckEvent($user, $item, $action, $granted);
        $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_ACCESS_CHECK);

        if (!$event->isGranted()) {
            return false;
        }

        return !(!$action->isViewOnly() && ($event->isReadOnly() || $this->isItemReadOnly($user, $item)))

        ;
    }

    public function canAccessFolder(UserInterface $user, VaultFolder $folder, VaultAccessAction $action): bool
    {
        $granted = $this->baseFolderGrant($user, $folder, $action);

        $event = new VaultFolderAccessCheckEvent($user, $folder, $action, $granted);
        $this->eventDispatcher->dispatch($event, VaultEvents::FOLDER_ACCESS_CHECK);

        return $event->isGranted();
    }

    public function isItemReadOnly(UserInterface $user, VaultItem $item): bool
    {
        $userId = UserIdResolver::getId($user);
        if ($userId === null) {
            return false;
        }

        $cacheKey = $userId . ':' . $item->getId();
        if (array_key_exists($cacheKey, $this->readOnlyCache)) {
            return $this->readOnlyCache[$cacheKey];
        }

        $event = new VaultItemReadOnlyEvent($user, $item);
        $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_READ_ONLY_RESOLVE);

        return $this->readOnlyCache[$cacheKey] = $event->isReadOnly();
    }

    /**
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

    private function baseItemGrant(UserInterface $user, VaultItem $item, VaultAccessAction $action): bool
    {
        if (UserIdResolver::isSameUser($item->getCreator(), $user)) {
            return true;
        }

        $permission = $this->resolveGrantPermission(
            VaultResourceType::Item,
            $item->getId(),
            $user,
            $item->getFolder()?->getId(),
        );

        return $this->actionAllowed($permission, $action);
    }

    private function baseFolderGrant(UserInterface $user, VaultFolder $folder, VaultAccessAction $action): bool
    {
        if (UserIdResolver::isSameUser($folder->getCreator(), $user)) {
            return true;
        }

        $permission = $this->resolveGrantPermission(
            VaultResourceType::Folder,
            $folder->getId(),
            $user,
            null,
        );

        return $this->actionAllowed($permission, $action);
    }

    private function resolveGrantPermission(
        VaultResourceType $type,
        string $resourceId,
        UserInterface $user,
        ?string $folderId,
    ): ?VaultPermission {
        $userId = UserIdResolver::getId($user);
        if ($userId === null) {
            return null;
        }

        $best = null;
        foreach ($this->grantRepository->findByResource($type, $resourceId) as $grant) {
            if ($grant->getGranteeType() === GranteeType::User && $grant->getGranteeId() === $userId) {
                $best = $this->maxPermission($best, $grant->getPermission());
            }
            if ($grant->getGranteeType() === GranteeType::Team && in_array($grant->getGranteeId(), $this->teamMembershipResolver->getTeamIdsForUser($user), true)) {
                $best = $this->maxPermission($best, $grant->getPermission());
            }
        }

        if ($folderId !== null) {
            foreach ($this->grantRepository->findByResource(VaultResourceType::Folder, $folderId) as $grant) {
                if ($grant->getGranteeType() === GranteeType::User && $grant->getGranteeId() === $userId) {
                    $best = $this->maxPermission($best, $grant->getPermission());
                }
                if ($grant->getGranteeType() === GranteeType::Team && in_array($grant->getGranteeId(), $this->teamMembershipResolver->getTeamIdsForUser($user), true)) {
                    $best = $this->maxPermission($best, $grant->getPermission());
                }
            }
        }

        foreach ($this->grantRepository->findByGrantee(GranteeType::User, $userId) as $grant) {
            if ($grant->getResourceType() === $type && $grant->getResourceId() === $resourceId) {
                $best = $this->maxPermission($best, $grant->getPermission());
            }
        }

        return $best;
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
