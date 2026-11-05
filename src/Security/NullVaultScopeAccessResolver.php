<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Default scope resolver: nobody is a member of any scope (scope features off).
 */
final readonly class NullVaultScopeAccessResolver implements VaultScopeAccessResolverInterface
{
    /**
     * Always null: no scope membership.
     */
    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        return null;
    }
}
