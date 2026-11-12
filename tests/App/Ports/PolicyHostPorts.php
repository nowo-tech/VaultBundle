<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * A 1.19 host: implements only the framework-free port interfaces (no Symfony type).
 */
final class PolicyHostPorts implements VaultAccessPolicyInterface, VaultTeamMembershipProviderInterface, VaultScopeAccessProviderInterface
{
    public function allowsAccess(?VaultUserRef $user = null): bool
    {
        return HostRules::allows('access', $user?->identifier, $user?->roles ?? []);
    }

    public function allowsCreate(?VaultUserRef $user = null): bool
    {
        return HostRules::allows('create', $user?->identifier, $user?->roles ?? []);
    }

    public function allowsList(?VaultUserRef $user = null): bool
    {
        return HostRules::allows('list', $user?->identifier, $user?->roles ?? []);
    }

    public function allowsRevoke(?VaultUserRef $user = null): bool
    {
        return HostRules::allows('revoke', $user?->identifier, $user?->roles ?? []);
    }

    public function teamIdsOf(VaultUserRef $user): array
    {
        return HostRules::teams($user->identifier);
    }

    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
    {
        return HostRules::scope($user->identifier, $scope);
    }
}
