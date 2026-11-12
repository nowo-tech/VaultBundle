<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/** Default resolver when no team membership service is configured. */
final class NullVaultTeamMembershipResolver implements VaultTeamMembershipProviderInterface, VaultTeamMembershipResolverInterface
{
    /**
     * Returns no teams (team sharing disabled).
     */
    public function teamIdsOf(VaultUserRef $user): array
    {
        return [];
    }

    /**
     * Returns no teams (team sharing disabled).
     */
    public function getTeamIdsForUser(UserInterface $user): array
    {
        return [];
    }
}
