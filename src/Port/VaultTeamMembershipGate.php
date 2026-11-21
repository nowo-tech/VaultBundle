<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The teams the bundle uses for team grants: the host's {@see VaultTeamMembershipProviderInterface} (the configured
 * `team_membership_resolver`, the application's alias of the interface or the built-in, which returns no teams).
 *
 * Since 2.0 the only interface asked (the deprecated `VaultTeamMembershipResolverInterface` was removed).
 *
 * @internal
 */
final readonly class VaultTeamMembershipGate
{
    /**
     * Wraps the team membership provider the build picked.
     */
    public function __construct(private VaultTeamMembershipProviderInterface $policy)
    {
    }

    /**
     * Team ids of the user used for team grants.
     *
     * @return list<string>
     */
    public function getTeamIdsForUser(UserInterface $user): array
    {
        return $this->policy->teamIdsOf(VaultUserRefs::fromUser($user));
    }
}
