<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The scope membership the bundle uses (behind the per-request memo): the host's
 * {@see VaultScopeAccessProviderInterface} (`scopes.access_resolver`, or the built-in, which knows no scope).
 *
 * Since 2.0 the only interface asked (the deprecated `VaultScopeAccessResolverInterface` was removed).
 *
 * @internal
 */
final readonly class VaultScopeAccessGate
{
    /**
     * Wraps the scope access provider the build picked.
     */
    public function __construct(private VaultScopeAccessProviderInterface $policy)
    {
    }

    /**
     * Scope membership of the user, or null when not a member.
     */
    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        return $this->policy->scopeAccessOf(VaultUserRefs::fromUser($user), $scope);
    }
}
