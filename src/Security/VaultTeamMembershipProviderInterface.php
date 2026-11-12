<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * The teams a user belongs to, for team grants (1.19.0): the framework-free twin of the deprecated
 * {@see VaultTeamMembershipResolverInterface} (REQ-ARCH-001 rule 3).
 *
 * Point `nowo_vault.team_membership_resolver` at a service implementing it. A service that implements both interfaces
 * is asked through this one. When a decorator of the deprecated interface is registered too, both are asked and only
 * the team ids both return count (fail closed).
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
