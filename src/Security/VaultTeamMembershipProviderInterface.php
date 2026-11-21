<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * The teams a user belongs to, for team grants (1.19.0; since 2.0 the only team port: the deprecated
 * `VaultTeamMembershipResolverInterface` was removed). Framework-free (REQ-ARCH-001 rule 3).
 *
 * Point `nowo_vault.team_membership_resolver` at a service implementing it; a configured service that does not
 * implement it fails the build.
 */
interface VaultTeamMembershipProviderInterface
{
    /**
     * Team ids of the user (compared byte for byte with the grantee ids of team grants).
     *
     * @return list<string>
     */
    public function teamIdsOf(VaultUserRef $user): array;
}
