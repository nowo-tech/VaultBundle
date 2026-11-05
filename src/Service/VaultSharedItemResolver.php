<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_values;

/**
 * Loads vault items shared with a user via grants (user or team), excluding own items.
 */
final readonly class VaultSharedItemResolver
{
    /**
     * Creates the resolver with the grant and item repositories and the team resolver.
     */
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
        private VaultItemRepositoryInterface $itemRepository,
        private VaultTeamMembershipResolverInterface $teamMembershipResolver,
        private ?VaultAccessGuard $accessGuard = null,
    ) {
    }

    /**
     * Returns the active items shared with the viewer.
     *
     * @return list<VaultItem>
     */
    public function resolve(UserInterface $viewer): array
    {
        $itemIds = $this->grantRepository->findGrantedResourceIds(
            $viewer,
            VaultResourceType::Item,
            $this->teamMembershipResolver->getTeamIdsForUser($viewer),
        );

        if ($itemIds === []) {
            return [];
        }

        return $this->withinMembership($viewer, $this->itemRepository->findByIdsForViewer($itemIds, $viewer));
    }

    /**
     * Returns the active items of one type shared with the viewer.
     *
     * @return list<VaultItem>
     */
    public function resolveByItemType(UserInterface $viewer, VaultItemType $itemType): array
    {
        $itemIds = $this->grantRepository->findGrantedResourceIds(
            $viewer,
            VaultResourceType::Item,
            $this->teamMembershipResolver->getTeamIdsForUser($viewer),
        );

        if ($itemIds === []) {
            return [];
        }

        return $this->withinMembership($viewer, $this->itemRepository->findByIdsForViewerAndItemType($itemIds, $viewer, $itemType));
    }

    /**
     * Drops scope items whose scope the viewer is not a member of (1.16.0 membership gate; memoized per request).
     *
     * @param list<VaultItem> $items
     *
     * @return list<VaultItem>
     */
    private function withinMembership(UserInterface $viewer, array $items): array
    {
        return array_values(array_filter($items, function (VaultItem $item) use ($viewer): bool {
            $scope = $item->getOwnerScope();

            return $scope === null || $this->accessGuard?->resolveScopeAccess($viewer, $scope) instanceof VaultScopeAccess;
        }));
    }
}
