<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * The teams the bundle uses for team grants (1.19.0): the host's {@see VaultTeamMembershipProviderInterface}, the
 * deprecated {@see VaultTeamMembershipResolverInterface} (exactly as in 1.18), or both, keeping only the team ids both
 * return (fail closed, in the order of the deprecated answer).
 *
 * @internal
 */
final readonly class VaultTeamMembershipGate implements VaultTeamMembershipResolverInterface
{
    public function __construct(
        private string $mode,
        private ?VaultTeamMembershipResolverInterface $legacy = null,
        private ?VaultTeamMembershipProviderInterface $policy = null,
    ) {
        VaultPortMode::assert($mode, $legacy, $policy, 'team_membership_resolver');
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        if ($this->mode === VaultPortMode::LEGACY) {
            /** @var VaultTeamMembershipResolverInterface $legacy asserted in the constructor */
            $legacy = $this->legacy;

            return $legacy->getTeamIdsForUser($user);
        }

        /** @var VaultTeamMembershipProviderInterface $policy asserted in the constructor */
        $policy  = $this->policy;
        $teamIds = $policy->teamIdsOf(VaultUserRefs::fromUser($user));
        if ($this->mode === VaultPortMode::POLICY) {
            return $teamIds;
        }

        /** @var VaultTeamMembershipResolverInterface $legacy asserted in the constructor */
        $legacy = $this->legacy;
        $both   = [];
        foreach ($legacy->getTeamIdsForUser($user) as $teamId) {
            if (in_array($teamId, $teamIds, true)) {
                $both[] = $teamId;
            }
        }

        return $both;
    }
}
