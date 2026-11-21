<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Internal\VaultCommandsTrait;
use Nowo\VaultBundle\Internal\VaultRowLock;
use Nowo\VaultBundle\Internal\VaultViews;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultFolderView;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The vault's {@see VaultFolderCommandsInterface} (2.1.0) over {@see VaultFolderService}, {@see VaultTrashService} and
 * {@see VaultAccessGuard}. Stateless (FrankenPHP worker mode, kernel reset off).
 */
final readonly class VaultFolderCommands implements VaultFolderCommandsInterface
{
    use VaultCommandsTrait;

    /**
     * @param EntityManagerInterface $entityManager the vault's private manager (exact-id lookups)
     */
    public function __construct(
        private VaultFolderService $folders,
        private VaultTrashService $trash,
        private VaultAccessGuard $guard,
        private VaultUserRefResolver $refs,
        private VaultUserReferenceResolverInterface $references,
        private EntityManagerInterface $entityManager,
        private ?VaultWriteUnit $writeUnit = null,
    ) {
    }

    public function create(VaultUserRef $actor, string $name, ?string $parentId = null): VaultFolderView
    {
        $user = $this->actor($actor);
        $name = self::requireText($name, self::MAX_NAME_LENGTH, 'folder name');

        return $this->inUnit(function () use ($user, $name, $parentId): VaultFolderView {
            $parent = null;
            if ($parentId !== null) {
                $parent = $this->find($parentId);
                if (!$parent instanceof VaultFolder || $parent->isDeleted() || $parent->getOwnerScope() !== null || !$parent->getOwner()->isUser($user)) {
                    throw new VaultOwnerException('The parent folder belongs to another owner.');
                }
            }

            return VaultViews::folder($this->folders->create($name, $user, $parent));
        });
    }

    public function createInScope(VaultUserRef $actor, string $scope, string $name, ?string $parentId = null): VaultFolderView
    {
        $user = $this->actor($actor);
        $name = self::requireText($name, self::MAX_NAME_LENGTH, 'folder name');

        return $this->inUnit(function () use ($user, $scope, $name, $parentId): VaultFolderView {
            // The scope gate first: a non-member learns nothing about the scope's folders (audit YF-I1).
            if ($this->guard->resolveScopeAccess($user, $scope)?->create !== true) {
                throw new VaultAccessDeniedException();
            }
            $parent = null;
            if ($parentId !== null) {
                $parent = $this->find($parentId);
                if (!$parent instanceof VaultFolder || $parent->isDeleted()) {
                    throw new VaultOwnerException('The parent folder belongs to another owner.');
                }
            }

            // createInScope() checks `create` in the scope and that the parent belongs to it.
            return VaultViews::folder($this->folders->createInScope($user, $name, $scope, $parent));
        });
    }

    public function rename(VaultUserRef $actor, string $folderId, string $name): VaultFolderView
    {
        $user = $this->actor($actor);
        $name = self::requireText($name, self::MAX_NAME_LENGTH, 'folder name');

        return $this->inUnit(function () use ($user, $folderId, $name): VaultFolderView {
            $folder = $this->allowed($user, $folderId, VaultAccessAction::Edit, false);
            // A folder's name is its owner's organisation (audit YF-I6, as the pages): the personal owner, or a scope
            // member who may Share the folder; a Write grantee may not rename it.
            $owner = $folder->getOwner();
            if ($owner->isScope() ? !$this->guard->canAccessFolder($user, $folder, VaultAccessAction::Share) : !$owner->isUser($user)) {
                throw new VaultAccessDeniedException();
            }

            return VaultViews::folder($this->folders->rename($folder, $name));
        });
    }

    public function moveToTrash(VaultUserRef $actor, string $folderId): int
    {
        $user = $this->actor($actor);

        return $this->inUnit(fn (): int => $this->trash->moveFolderToTrash($this->allowed($user, $folderId, VaultAccessAction::Delete, false)));
    }

    public function restore(VaultUserRef $actor, string $folderId): VaultFolderView
    {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $folderId): VaultFolderView {
            $folder = $this->allowed($user, $folderId, VaultAccessAction::Restore, true);
            $this->trash->restoreFolder($folder);

            return VaultViews::folder($folder);
        });
    }

    public function purge(VaultUserRef $actor, string $folderId): void
    {
        $user = $this->actor($actor);

        $this->inUnit(function () use ($user, $folderId): void {
            $this->trash->purgeFolder($this->allowed($user, $folderId, VaultAccessAction::Purge, true));
        });
    }

    /**
     * The folder with exactly this id when the actor may perform the action on it.
     *
     * @throws VaultAccessDeniedException otherwise (also for an unknown folder, or a trashed one when not allowed)
     */
    private function allowed(UserInterface $user, string $folderId, VaultAccessAction $action, bool $allowTrashed): VaultFolder
    {
        $folder = $this->find($folderId);
        if (!$folder instanceof VaultFolder || (!$allowTrashed && $folder->isDeleted()) || !$this->guard->canAccessFolder($user, $folder, $action)) {
            throw new VaultAccessDeniedException();
        }

        return $folder;
    }

    private function find(string $folderId): ?VaultFolder
    {
        // Row lock inside the unit; a folder deleted meanwhile is not found (audit YF-L1).
        return VaultRowLock::find($this->entityManager, VaultFolder::class, $folderId);
    }
}
