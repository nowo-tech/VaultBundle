<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllVaultAccessChecker implements VaultAccessCheckerInterface
{
    public function canAccess(?UserInterface $user = null): bool
    {
        return true;
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        return true;
    }

    public function canList(?UserInterface $user = null): bool
    {
        return true;
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        return true;
    }
}
