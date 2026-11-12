<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * A user's standing in a host scope (project, organization) for the scope vaults (1.19.0): the framework-free twin of
 * the deprecated {@see VaultScopeAccessResolverInterface} (REQ-ARCH-001 rule 3).
 *
 * Point `nowo_vault.scopes.access_resolver` at a service implementing it. A service that implements both interfaces is
 * asked through this one. When a decorator of the deprecated interface is registered too, both are asked and the
 * weaker answer wins (null when either is null, the lower baseline, each flag only when both set it: fail closed).
 * The bundle memoizes the answer for one request only and never passes a malformed scope key.
 */
interface VaultScopeAccessProviderInterface
{
    /**
     * The user's standing in the scope, or null when the user is not a member.
     */
    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess;
}
