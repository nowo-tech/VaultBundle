<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application-specific access rules for Vault manage routes.
 *
 * Replace this interface in the container to integrate team-based or custom ACL logic.
 *
 * Test copy of the v1.18.0 interface (removed in 2.0), for the equivalence reference chain only.
 */
interface VaultAccessCheckerInterfaceV1180
{
    /**
     * Whether the user may access the vault UI.
     */
    public function canAccess(?UserInterface $user = null): bool;

    /**
     * Whether the user may create items.
     */
    public function canCreate(?UserInterface $user = null): bool;

    /**
     * Whether the user may list items.
     */
    public function canList(?UserInterface $user = null): bool;

    /**
     * Whether the user may revoke grants and delete items.
     */
    public function canRevoke(?UserInterface $user = null): bool;
}
