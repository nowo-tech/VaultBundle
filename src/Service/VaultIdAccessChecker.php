<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_slice;
use function array_unique;
use function array_values;
use function in_array;

/**
 * The vault's {@see VaultAccessCheckInterface} (2.1.0): resolves the user reference (audit NJ-1) and the resource by
 * exact id on the vault's private manager, then asks {@see VaultAccessGuard}, the service the vault pages use. Stateless.
 */
final readonly class VaultIdAccessChecker implements VaultAccessCheckInterface
{
    /** Ids read per call of {@see self::itemPermissions()}. */
    public const MAX_IDS = 500;

    /**
     * @param EntityManagerInterface $entityManager the vault's private manager
     */
    public function __construct(
        private VaultAccessGuard $guard,
        private VaultUserRefResolver $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function canAccessItem(VaultUserRef $user, string $itemId, VaultAccessAction $action): bool
    {
        $actor = $this->users->resolve($user);
        $item  = $this->item($itemId, $this->reachesTrash($action));

        return $actor instanceof UserInterface && $item instanceof VaultItem && $this->guard->canAccessItem($actor, $item, $action);
    }

    public function canAccessFolder(VaultUserRef $user, string $folderId, VaultAccessAction $action): bool
    {
        $actor  = $this->users->resolve($user);
        $folder = $this->folder($folderId, $this->reachesTrash($action));

        return $actor instanceof UserInterface && $folder instanceof VaultFolder && $this->guard->canAccessFolder($actor, $folder, $action);
    }

    public function isItemReadOnly(VaultUserRef $user, string $itemId): bool
    {
        $actor = $this->users->resolve($user);
        $item  = $this->item($itemId, false);

        return $actor instanceof UserInterface && $item instanceof VaultItem && $this->guard->isItemReadOnly($actor, $item);
    }

    public function itemPermissions(VaultUserRef $user, array $itemIds): array
    {
        $actor = $this->users->resolve($user);
        $ids   = array_slice(array_values(array_unique($itemIds)), 0, self::MAX_IDS);
        if (!$actor instanceof UserInterface || $ids === []) {
            return [];
        }

        /** @var list<VaultItem> $loaded */
        $loaded = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(VaultItem::class, 'i')
            ->where('i.id IN (:ids)')
            ->andWhere('i.deletedAt IS NULL')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($loaded as $item) {
            // Exact id (REQ-SEC-006).
            if (in_array($item->getId(), $ids, true)) {
                $items[] = $item;
            }
        }

        return $this->guard->resolvePermissionMap($actor, $items);
    }

    public function scopeAccess(VaultUserRef $user, string $scope): ?VaultScopeAccess
    {
        $actor = $this->users->resolve($user);

        return $actor instanceof UserInterface ? $this->guard->resolveScopeAccess($actor, $scope) : null;
    }

    /**
     * Restore and Purge act on trashed resources; every other action only on active ones (as the vault pages).
     */
    private function reachesTrash(VaultAccessAction $action): bool
    {
        return $action === VaultAccessAction::Restore || $action === VaultAccessAction::Purge;
    }

    private function item(string $itemId, bool $allowTrashed): ?VaultItem
    {
        $item = $itemId !== '' ? $this->entityManager->find(VaultItem::class, $itemId) : null;

        return $item instanceof VaultItem && $item->getId() === $itemId && ($allowTrashed || !$item->isDeleted()) ? $item : null;
    }

    private function folder(string $folderId, bool $allowTrashed): ?VaultFolder
    {
        $folder = $folderId !== '' ? $this->entityManager->find(VaultFolder::class, $folderId) : null;

        return $folder instanceof VaultFolder && $folder->getId() === $folderId && ($allowTrashed || !$folder->isDeleted()) ? $folder : null;
    }
}
