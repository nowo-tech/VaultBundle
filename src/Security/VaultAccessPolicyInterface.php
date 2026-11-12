<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Who may use the vault features (1.19.0): the framework-free twin of the deprecated {@see VaultAccessCheckerInterface}
 * (REQ-ARCH-001 rule 3: a {@see VaultUserRef} instead of the Symfony `UserInterface`).
 *
 * Point `nowo_vault.security.access_checker` at a service implementing it (or alias this interface to your service).
 * A service that implements both interfaces is asked through this one. When a decorator of the deprecated interface is
 * registered too, both are asked and both must allow (fail closed). Answer false to deny; an exception is never turned
 * into an allow.
 *
 * `null` means no signed-in user (the browser extension and the pages pass the user they act for). The user object of
 * the reference ({@see VaultUserRef::user()}) is the one the bundle would have passed to the deprecated interface.
 */
interface VaultAccessPolicyInterface
{
    /**
     * Whether the user may open the vault pages and reveal secrets they can access.
     */
    public function allowsAccess(?VaultUserRef $user = null): bool;

    /**
     * Whether the user may create items and folders.
     */
    public function allowsCreate(?VaultUserRef $user = null): bool;

    /**
     * Whether the user may list items.
     */
    public function allowsList(?VaultUserRef $user = null): bool;

    /**
     * Whether the user may revoke grants and delete items.
     */
    public function allowsRevoke(?VaultUserRef $user = null): bool;
}
