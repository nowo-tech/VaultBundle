<?php

declare(strict_types=1);

// Literal copy of v1.18.0 src/Security/AllowAllVaultAccessChecker.php (equivalence reference of 1.19.0): only the namespace, the class name and the
// imports differ. Never edit the logic.

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllVaultAccessCheckerV1180 implements VaultAccessCheckerInterface
{
    /**
     * Always allows access to the vault UI.
     */
    public function canAccess(?UserInterface $user = null): bool
    {
        return true;
    }

    /**
     * Always allows creating items.
     */
    public function canCreate(?UserInterface $user = null): bool
    {
        return true;
    }

    /**
     * Always allows listing items.
     */
    public function canList(?UserInterface $user = null): bool
    {
        return true;
    }

    /**
     * Always allows revoking and deleting.
     */
    public function canRevoke(?UserInterface $user = null): bool
    {
        return true;
    }
}
