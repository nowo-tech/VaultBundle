<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Permissive checker used only when security.allow_unauthenticated is true (demo/dev).
 */
final class AllowAllVaultAccessChecker implements VaultAccessPolicyInterface
{
    /**
     * Always true (demo / dev only).
     */
    public function allowsAccess(?VaultUserRef $user = null): bool
    {
        return true;
    }

    /**
     * Always true (demo / dev only).
     */
    public function allowsCreate(?VaultUserRef $user = null): bool
    {
        return true;
    }

    /**
     * Always true (demo / dev only).
     */
    public function allowsList(?VaultUserRef $user = null): bool
    {
        return true;
    }

    /**
     * Always true (demo / dev only).
     */
    public function allowsRevoke(?VaultUserRef $user = null): bool
    {
        return true;
    }
}
