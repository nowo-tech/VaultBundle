<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * A user's standing in a host scope (project, organization) for the scope vaults (1.19.0; since 2.0 the only scope
 * port: the deprecated `VaultScopeAccessResolverInterface` was removed). Framework-free (REQ-ARCH-001 rule 3).
 *
 * Point `nowo_vault.scopes.access_resolver` at a service implementing it; a configured service that does not implement
 * it fails the build. The bundle memoizes the answer for one request only and never passes a malformed scope key.
 */
interface VaultScopeAccessProviderInterface
{
    /**
     * The user's standing in the scope, or null when the user is not a member.
     */
    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess;
}
