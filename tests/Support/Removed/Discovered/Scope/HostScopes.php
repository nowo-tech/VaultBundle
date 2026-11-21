<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Removed\Discovered\Scope;

use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.19 host class implementing both scope ports (as the 1.19 UPGRADING allowed while code still injected the
 * deprecated one), aliased to the 2.x port with `#[AsAlias]` and registered by resource discovery. Since 2.0 it cannot
 * be loaded: the build must fail instead of dropping the alias. Excluded from static analysis (rector.php); never
 * instantiated.
 */
#[AsAlias(VaultScopeAccessProviderInterface::class)]
final class HostScopes implements VaultScopeAccessProviderInterface, VaultScopeAccessResolverInterface
{
    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
    {
        return null;
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        return null;
    }
}
