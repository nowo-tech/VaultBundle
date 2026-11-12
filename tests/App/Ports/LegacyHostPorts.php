<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.18 host: implements only the deprecated port interfaces.
 */
final class LegacyHostPorts implements VaultAccessCheckerInterface, VaultTeamMembershipResolverInterface, VaultScopeAccessResolverInterface
{
    public function canAccess(?UserInterface $user = null): bool
    {
        return HostRules::allows('access', $user?->getUserIdentifier(), $user?->getRoles() ?? []);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        return HostRules::allows('create', $user?->getUserIdentifier(), $user?->getRoles() ?? []);
    }

    public function canList(?UserInterface $user = null): bool
    {
        return HostRules::allows('list', $user?->getUserIdentifier(), $user?->getRoles() ?? []);
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        return HostRules::allows('revoke', $user?->getUserIdentifier(), $user?->getRoles() ?? []);
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        return HostRules::teams($user->getUserIdentifier());
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        return HostRules::scope($user->getUserIdentifier(), $scope);
    }
}
