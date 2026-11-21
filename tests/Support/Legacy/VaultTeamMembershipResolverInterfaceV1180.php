<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Resolves team identifiers for a user (for VaultGrant grantee_type=team).
 *
 * Implement in the application or register a custom service id in
 * nowo_vault.team_membership_resolver.
 *
 * Test copy of the v1.18.0 interface (removed in 2.0), for the equivalence reference chain only.
 */
interface VaultTeamMembershipResolverInterfaceV1180
{
    /**
     * Returns the ids of the teams the user belongs to.
     *
     * @return list<string> Team ids the user belongs to
     */
    public function getTeamIdsForUser(UserInterface $user): array;
}
