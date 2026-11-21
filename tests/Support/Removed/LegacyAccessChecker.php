<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Removed;

use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.x host access checker that still implements the access port removed in 2.0, named by
 * `nowo_vault.security.access_checker`. It cannot be loaded: the build must fail with a message naming the 2.x port.
 * Excluded from static analysis (rector.php); never instantiated.
 */
final class LegacyAccessChecker implements VaultAccessCheckerInterface
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
