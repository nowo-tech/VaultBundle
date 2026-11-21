<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/** Default resolver when no team membership service is configured. */
final class NullVaultTeamMembershipResolver implements VaultTeamMembershipProviderInterface
{
    /**
     * Returns no teams (team sharing disabled).
     */
    public function teamIdsOf(VaultUserRef $user): array
    {
        return [];
    }
}
