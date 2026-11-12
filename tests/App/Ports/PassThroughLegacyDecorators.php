<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function assert;

/**
 * A host decorator of the three deprecated access ports (an audit log): passes every call through and counts it. One
 * class decorates the three interfaces; the kernel registers one instance per decorated port.
 */
final class PassThroughLegacyDecorators implements VaultAccessCheckerInterface, VaultTeamMembershipResolverInterface, VaultScopeAccessResolverInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly object $inner,
    ) {
    }

    public function canAccess(?UserInterface $user = null): bool
    {
        ++$this->calls;

        return $this->access()->canAccess($user);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        ++$this->calls;

        return $this->access()->canCreate($user);
    }

    public function canList(?UserInterface $user = null): bool
    {
        ++$this->calls;

        return $this->access()->canList($user);
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        ++$this->calls;

        return $this->access()->canRevoke($user);
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        ++$this->calls;
        assert($this->inner instanceof VaultTeamMembershipResolverInterface);

        return $this->inner->getTeamIdsForUser($user);
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        ++$this->calls;
        assert($this->inner instanceof VaultScopeAccessResolverInterface);

        return $this->inner->resolve($user, $scope);
    }

    private function access(): VaultAccessCheckerInterface
    {
        assert($this->inner instanceof VaultAccessCheckerInterface);

        return $this->inner;
    }
}
