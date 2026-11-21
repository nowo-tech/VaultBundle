<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultItemChangedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Internal\VaultCommandsTrait;
use Nowo\VaultBundle\Internal\VaultViews;
use Nowo\VaultBundle\Support\VaultItemEditToken;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultItemView;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash_equals;

/**
 * The vault's {@see VaultItemCommandsInterface} (2.1.0) over the write services the vault pages use
 * ({@see VaultItemCreator}, {@see VaultItemUpdater}, {@see VaultTagService}, {@see VaultTrashService}) and
 * {@see VaultAccessGuard}. Stateless (FrankenPHP worker mode, kernel reset off).
 */
final readonly class VaultItemCommands implements VaultItemCommandsInterface
{
    use VaultCommandsTrait;

    /**
     * @param EntityManagerInterface $entityManager the vault's private manager (exact-id lookups)
     */
    public function __construct(
        private VaultItemCreator $creator,
        private VaultItemUpdater $updater,
        private VaultTagService $tags,
        private VaultTrashService $trash,
        private VaultAccessGuard $guard,
        private VaultUserRefResolver $refs,
        private VaultUserReferenceResolverInterface $references,
        private EntityManagerInterface $entityManager,
        private ?VaultWriteUnit $writeUnit = null,
    ) {
    }

    public function create(VaultUserRef $actor, VaultItemType $type, string $title, array $payload, ?string $folderId = null, array $tagNames = []): VaultItemView
    {
        $user  = $this->actor($actor);
        $title = self::requireText($title, self::MAX_TITLE_LENGTH, 'item title');

        return $this->inUnit(function () use ($user, $type, $title, $payload, $folderId, $tagNames): VaultItemView {
            $folder = null;
            if ($folderId !== null) {
                $folder = $this->folder($folderId);
                if (!$folder instanceof VaultFolder || $folder->getOwnerScope() !== null || !$folder->getOwner()->isUser($user)) {
                    throw new VaultOwnerException('The folder belongs to another owner.');
                }
            }

            $item = $this->creator->create($type, $title, $user, $payload, $folder);
            if ($tagNames !== []) {
                $this->tags->syncItemTags($item, $user, $tagNames);
            }

            return VaultViews::item($item);
        });
    }

    public function createInScope(VaultUserRef $actor, string $scope, VaultItemType $type, string $title, array $payload, ?string $folderId = null, array $tagNames = []): VaultItemView
    {
        $user  = $this->actor($actor);
        $title = self::requireText($title, self::MAX_TITLE_LENGTH, 'item title');

        return $this->inUnit(function () use ($user, $scope, $type, $title, $payload, $folderId, $tagNames): VaultItemView {
            $folder = null;
            if ($folderId !== null) {
                $folder = $this->folder($folderId) ?? throw new VaultOwnerException('The folder belongs to another owner.');
            }

            // createInScope() checks `create` in the scope and that the folder is an active folder of the scope.
            $item = $this->creator->createInScope($user, $type, $title, $scope, $payload, $folder);
            if ($tagNames !== []) {
                $this->tags->syncItemTags($item, $user, $tagNames);
            }

            return VaultViews::item($item);
        });
    }

    public function update(VaultUserRef $actor, string $itemId, string $title, array $payload, ?string $expectedEditToken = null): VaultItemView
    {
        $user  = $this->actor($actor);
        $title = self::requireText($title, self::MAX_TITLE_LENGTH, 'item title');

        return $this->inUnit(function () use ($user, $itemId, $title, $payload, $expectedEditToken): VaultItemView {
            $item = $this->lockedItem($itemId);
            $this->deny($user, $item, VaultAccessAction::Edit);
            if ($expectedEditToken !== null && !hash_equals(VaultItemEditToken::of($item), $expectedEditToken)) {
                throw new VaultItemChangedException();
            }

            return VaultViews::item($this->updater->update($item, $title, $payload, $item->getFolder(), $user));
        });
    }

    public function move(VaultUserRef $actor, string $itemId, ?string $folderId): VaultItemView
    {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $itemId, $folderId): VaultItemView {
            $item = $this->lockedItem($itemId);
            $this->denyUnlessOrganiser($user, $item);
            $folder = null;
            if ($folderId !== null) {
                $folder = $this->folder($folderId);
                if (!$folder instanceof VaultFolder || !$folder->getOwner()->equals($item->getOwner())) {
                    throw new VaultOwnerException('The folder belongs to another owner.');
                }
            }

            return VaultViews::item($this->updater->move($item, $folder, $user));
        });
    }

    public function retag(VaultUserRef $actor, string $itemId, array $tagNames): VaultItemView
    {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $itemId, $tagNames): VaultItemView {
            $item = $this->lockedItem($itemId);
            $this->denyUnlessOrganiser($user, $item);
            $this->tags->syncItemTags($item, $user, $tagNames);

            return VaultViews::item($item);
        });
    }

    public function moveToTrash(VaultUserRef $actor, string $itemId): VaultItemView
    {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $itemId): VaultItemView {
            $item = $this->lockedItem($itemId);
            $this->deny($user, $item, VaultAccessAction::Delete);
            $this->trash->moveItemToTrash($item, $user);

            return VaultViews::item($item);
        });
    }

    public function restore(VaultUserRef $actor, string $itemId): VaultItemView
    {
        $user = $this->actor($actor);

        return $this->inUnit(function () use ($user, $itemId): VaultItemView {
            $item = $this->lockedItem($itemId, allowTrashed: true);
            $this->deny($user, $item, VaultAccessAction::Restore);
            $this->trash->restoreItem($item, $user);

            return VaultViews::item($item);
        });
    }

    public function purge(VaultUserRef $actor, string $itemId): void
    {
        $user = $this->actor($actor);

        $this->inUnit(function () use ($user, $itemId): void {
            $item = $this->lockedItem($itemId, allowTrashed: true);
            $this->deny($user, $item, VaultAccessAction::Purge);
            $this->trash->purgeItem($item, $user);
        });
    }

    /**
     * The item with exactly this id, its row locked for the unit (a concurrent write waits, then this call sees the
     * committed row).
     *
     * @throws VaultAccessDeniedException when there is none (or it is trashed and that is not allowed)
     */
    private function lockedItem(string $itemId, bool $allowTrashed = false): VaultItem
    {
        $item = $itemId !== '' ? $this->entityManager->find(VaultItem::class, $itemId) : null;
        if (!$item instanceof VaultItem || $item->getId() !== $itemId) {
            throw new VaultAccessDeniedException();
        }
        $this->writeUnit?->lockForUpdate($item);
        if (!$allowTrashed && $item->isDeleted()) {
            throw new VaultAccessDeniedException();
        }

        return $item;
    }

    /**
     * The active folder with exactly this id, or null.
     */
    private function folder(string $folderId): ?VaultFolder
    {
        $folder = $folderId !== '' ? $this->entityManager->find(VaultFolder::class, $folderId) : null;

        return $folder instanceof VaultFolder && $folder->getId() === $folderId && !$folder->isDeleted() ? $folder : null;
    }

    /**
     * @throws VaultAccessDeniedException unless the guard allows the action
     */
    private function deny(UserInterface $user, VaultItem $item, VaultAccessAction $action): void
    {
        if (!$this->guard->canAccessItem($user, $item, $action)) {
            throw new VaultAccessDeniedException();
        }
    }

    /**
     * Folder and tags are the owner's organisation (SEC-004 M1, L1, L2): the personal owner, or a scope member who may
     * Share the item.
     *
     * @throws VaultAccessDeniedException otherwise
     */
    private function denyUnlessOrganiser(UserInterface $user, VaultItem $item): void
    {
        $owner = $item->getOwner();
        $may   = $owner->isScope() ? $this->guard->canAccessItem($user, $item, VaultAccessAction::Share) : $owner->isUser($user) && $this->guard->canAccessItem($user, $item, VaultAccessAction::Edit);
        if (!$may) {
            throw new VaultAccessDeniedException();
        }
    }
}
