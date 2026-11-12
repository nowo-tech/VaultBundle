<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves team identifiers for a user (for VaultGrant grantee_type=team).
 *
 * Implement in the application or register a custom service id in
 * nowo_vault.team_membership_resolver.
 *
 * @deprecated since 1.19.0, implement {@see VaultTeamMembershipProviderInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface VaultTeamMembershipResolverInterface
{
    /**
     * Returns the ids of the teams the user belongs to.
     *
     * @return list<string> Team ids the user belongs to
     */
    public function getTeamIdsForUser(UserInterface $user): array;
}
