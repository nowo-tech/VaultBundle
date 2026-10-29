<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/** Default resolver when no team membership service is configured. */
final class NullVaultTeamMembershipResolver implements VaultTeamMembershipResolverInterface
{
    /**
     * Returns no teams (team sharing disabled).
     */
    public function getTeamIdsForUser(UserInterface $user): array
    {
        return [];
    }
}
