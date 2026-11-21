<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultReauthContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash;
use function is_string;

/**
 * The re-authentication hook the decrypted export asks: the host's {@see VaultReauthenticationProviderInterface}
 * (`export.reauth_checker`), given a {@see VaultReauthContext} built from the request.
 *
 * Since 2.0 the only interface asked (the deprecated `VaultReauthenticationCheckerInterface` was removed).
 *
 * @internal
 */
final readonly class VaultReauthenticationGate
{
    /**
     * Wraps the re-authentication provider the build picked.
     */
    public function __construct(private VaultReauthenticationProviderInterface $policy)
    {
    }

    /**
     * Fresh re-authentication proof of the user for the purpose, or null.
     */
    public function getProof(UserInterface $user, Request $request, string $purpose): ?VaultReauthProof
    {
        return $this->policy->proofFor(self::context($request, $purpose, $user));
    }

    /**
     * Marks the proof as used.
     */
    public function consume(VaultReauthProof $proof): void
    {
        $this->policy->consumeProof($proof);
    }

    /**
     * URL where the user re-authenticates before coming back to the given path.
     */
    public function getReauthenticationUrl(Request $request, string $purpose, string $returnTo): string
    {
        return $this->policy->reauthenticationUrl(self::context($request, $purpose, null), $returnTo);
    }

    /**
     * The framework-free view of the request: purpose, user, method, route; path and session binding hidden.
     */
    public static function context(Request $request, string $purpose, ?UserInterface $user): VaultReauthContext
    {
        $session = $request->hasSession() ? $request->getSession()->getId() : '';
        $route   = $request->attributes->get('_route');

        return new VaultReauthContext(
            $purpose,
            VaultUserRefs::fromNullableUser($user),
            $request->getMethod(),
            is_string($route) ? $route : null,
            $request->getPathInfo(),
            $session !== '' ? hash('sha256', $session) : '',
        );
    }
}
