<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

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
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
        private VaultItemRepositoryInterface $itemRepository,
        private VaultTeamMembershipResolverInterface $teamMembershipResolver,
    ) {
    }

    /**
     * @return list<\Nowo\VaultBundle\Entity\VaultItem>
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
}
