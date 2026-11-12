<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\ValueObject\VaultReauthContext;

/**
 * Fresh interactive re-authentication before the decrypted personal export (1.19.0): the framework-free twin of the
 * deprecated {@see VaultReauthenticationCheckerInterface} (REQ-ARCH-001 rule 3: a {@see VaultReauthContext} instead of
 * the Symfony `Request` and `UserInterface`).
 *
 * Point `nowo_vault.export.reauth_checker` at a service implementing it. A service that implements both interfaces is
 * asked through this one, unless a decorator of the deprecated interface is registered: then the deprecated interface
 * stays the one asked (registering a decorator of the deprecated interface together with another service behind this
 * one fails the build). The bundle validates every proof itself ({@see VaultReauthProofValidator}).
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
