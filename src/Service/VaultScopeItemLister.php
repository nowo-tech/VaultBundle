<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use LogicException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_keys;
use function max;

/**
 * Lists the items of a scope vault for one member (1.16.0). A member with a baseline (`read` or more) sees every
 * active item; a member without one sees only the items they hold an active grant on. A non-member is refused.
 */
final readonly class VaultScopeItemLister
{
    /**
     * Stores the item repository and the access guard.
     */
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultAccessGuard $accessGuard,
    ) {
    }

    /**
     * One page of the scope items visible to the viewer, the total, and the effective permission per listed item.
     *
     * @throws VaultAccessDeniedException when the viewer is not a member, or a member without baseline and without any grant
     *
     * @return array{items: list<VaultItem>, total: int, permissions: array<string, VaultPermission|null>, access: VaultScopeAccess}
     */
    public function list(
        UserInterface $viewer,
        string $scope,
        ?string $folderId = null,
        ?string $searchQuery = null,
        ?string $tagId = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $repository = $this->itemRepository;
        if (!$repository instanceof VaultScopeItemRepositoryInterface) {
            throw new LogicException('Scope vaults need an item repository implementing VaultScopeItemRepositoryInterface.');
        }

        $access = $this->accessGuard->resolveScopeAccess($viewer, $scope);
        if (!$access instanceof VaultScopeAccess) {
            throw new VaultAccessDeniedException();
        }

        $onlyIds = null;
        if (!$access->baseline instanceof VaultPermission) {
            // Grants only: the ids of the scope items the viewer may View (constant number of queries).
            $onlyIds = array_keys(array_filter($this->accessGuard->resolvePermissionMap($viewer, $repository->findActiveByScope($scope))));
            if ($onlyIds === []) {
                throw new VaultAccessDeniedException();
            }
        }

        $perPage = max(1, $perPage);
        $result  = $repository->paginateByScope($scope, $onlyIds, $folderId, $tagId, $searchQuery, (max(1, $page) - 1) * $perPage, $perPage);

        return $result + ['permissions' => $this->accessGuard->resolvePermissionMap($viewer, $result['items']), 'access' => $access];
    }
}
