<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Default scope resolver: nobody is a member of any scope (scope features off).
 */
final readonly class NullVaultScopeAccessResolver implements VaultScopeAccessProviderInterface
{
    /**
     * Always null: no scope membership.
     */
    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
    {
        return null;
    }
}
