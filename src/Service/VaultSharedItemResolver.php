<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

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

        return $this->itemRepository->findByIdsForViewer($itemIds, $viewer);
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

        return $this->itemRepository->findByIdsForViewerAndItemType($itemIds, $viewer, $itemType);
    }
}
