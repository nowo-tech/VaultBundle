<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A host migrating to 1.19: implements both interfaces of each port (the bundle asks the 1.19 ones). Counts the calls
 * of each interface.
 */
final class BothHostPorts implements VaultAccessCheckerInterface, VaultAccessPolicyInterface, VaultTeamMembershipResolverInterface, VaultTeamMembershipProviderInterface, VaultScopeAccessResolverInterface, VaultScopeAccessProviderInterface
{
    /** @var array{legacy: int, policy: int} */
    public array $calls = ['legacy' => 0, 'policy' => 0];

    private LegacyHostPorts $legacy;

    private PolicyHostPorts $policy;

    public function __construct()
    {
        $this->legacy = new LegacyHostPorts();
        $this->policy = new PolicyHostPorts();
    }

    public function canAccess(?UserInterface $user = null): bool
    {
        ++$this->calls['legacy'];

        return $this->legacy->canAccess($user);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        ++$this->calls['legacy'];

        return $this->legacy->canCreate($user);
    }

    public function canList(?UserInterface $user = null): bool
    {
        ++$this->calls['legacy'];

        return $this->legacy->canList($user);
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        ++$this->calls['legacy'];

        return $this->legacy->canRevoke($user);
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        ++$this->calls['legacy'];

        return $this->legacy->getTeamIdsForUser($user);
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        ++$this->calls['legacy'];

        return $this->legacy->resolve($user, $scope);
    }

    public function allowsAccess(?VaultUserRef $user = null): bool
    {
        ++$this->calls['policy'];

        return $this->policy->allowsAccess($user);
    }

    public function allowsCreate(?VaultUserRef $user = null): bool
    {
        ++$this->calls['policy'];

        return $this->policy->allowsCreate($user);
    }

    public function allowsList(?VaultUserRef $user = null): bool
    {
        ++$this->calls['policy'];

        return $this->policy->allowsList($user);
    }

    public function allowsRevoke(?VaultUserRef $user = null): bool
    {
        ++$this->calls['policy'];

        return $this->policy->allowsRevoke($user);
    }

    public function teamIdsOf(VaultUserRef $user): array
    {
        ++$this->calls['policy'];

        return $this->policy->teamIdsOf($user);
    }

    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
    {
        ++$this->calls['policy'];

        return $this->policy->scopeAccessOf($user, $scope);
    }
}
