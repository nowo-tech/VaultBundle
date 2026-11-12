<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Application-specific access rules for Vault manage routes.
 *
 * Replace this interface in the container to integrate team-based or custom ACL logic.
 *
 * @deprecated since 1.19.0, implement {@see VaultAccessPolicyInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface VaultAccessCheckerInterface
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
