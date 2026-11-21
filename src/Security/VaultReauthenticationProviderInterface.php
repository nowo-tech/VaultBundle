<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultReauthContext;

/**
 * Fresh interactive re-authentication before the decrypted personal export (1.19.0; since 2.0 the only re-authentication
 * port: the deprecated `VaultReauthenticationCheckerInterface` was removed). Framework-free (REQ-ARCH-001 rule 3: a
 * {@see VaultReauthContext} instead of the Symfony `Request` and `UserInterface`).
 *
 * Point `nowo_vault.export.reauth_checker` at a service implementing it; a configured service that does not implement
 * it fails the build. The bundle validates every proof itself ({@see VaultReauthProofValidator}).
 */
interface VaultReauthenticationProviderInterface
{
    /**
     * The fresh re-authentication proof for this user and purpose, or null when there is none.
     */
    public function proofFor(VaultReauthContext $context): ?VaultReauthProof;

    /**
     * Marks the proof as used (single use).
     */
    public function consumeProof(VaultReauthProof $proof): void;

    /**
     * Where to send the user to re-authenticate, coming back to `$returnTo` afterwards.
     */
    public function reauthenticationUrl(VaultReauthContext $context, string $returnTo): string;
}
