<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Who may use the vault features (1.19.0; since 2.0 the only access port: the deprecated `VaultAccessCheckerInterface`
 * was removed). Framework-free (REQ-ARCH-001 rule 3: a {@see VaultUserRef} instead of the Symfony `UserInterface`).
 *
 * Point `nowo_vault.security.access_checker` at a service implementing it (or alias this interface to your service);
 * a configured service that does not implement it fails the build. Answer false to deny; an exception is never turned
 * into an allow.
 *
 * `null` means no signed-in user (the browser extension and the pages pass the user they act for). The user object of
 * the reference ({@see VaultUserRef::user()}) is the Symfony user the vault works with.
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
