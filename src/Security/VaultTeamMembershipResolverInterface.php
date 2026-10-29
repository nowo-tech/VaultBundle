<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves team identifiers for a user (for VaultGrant grantee_type=team).
 *
 * Implement in the application or register a custom service id in
 * nowo_vault.team_membership_resolver.
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
