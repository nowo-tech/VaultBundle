<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultReauthContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function hash;
use function is_string;

/**
 * The re-authentication hook the decrypted export asks (1.19.0): the host's
 * {@see VaultReauthenticationProviderInterface} (given a {@see VaultReauthContext} built from the request), or the
 * deprecated {@see VaultReauthenticationCheckerInterface}, exactly as in 1.18. Never both: the build picks one.
 *
 * @internal
 */
final readonly class VaultReauthenticationGate implements VaultReauthenticationCheckerInterface
{
    public function __construct(
        private string $mode,
        private ?VaultReauthenticationCheckerInterface $legacy = null,
        private ?VaultReauthenticationProviderInterface $policy = null,
    ) {
        VaultPortMode::assert($mode === VaultPortMode::BOTH ? '' : $mode, $legacy, $policy, 'export.reauth_checker');
    }

    public function getProof(UserInterface $user, Request $request, string $purpose): ?VaultReauthProof
    {
        if ($this->legacy instanceof VaultReauthenticationCheckerInterface && $this->mode === VaultPortMode::LEGACY) {
            return $this->legacy->getProof($user, $request, $purpose);
        }

        return $this->provider()->proofFor(self::context($request, $purpose, $user));
    }

    public function consume(VaultReauthProof $proof): void
    {
        if ($this->legacy instanceof VaultReauthenticationCheckerInterface && $this->mode === VaultPortMode::LEGACY) {
            $this->legacy->consume($proof);

            return;
        }

        $this->provider()->consumeProof($proof);
    }

    public function getReauthenticationUrl(Request $request, string $purpose, string $returnTo): string
    {
        if ($this->legacy instanceof VaultReauthenticationCheckerInterface && $this->mode === VaultPortMode::LEGACY) {
            return $this->legacy->getReauthenticationUrl($request, $purpose, $returnTo);
        }

        return $this->provider()->reauthenticationUrl(self::context($request, $purpose, null), $returnTo);
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

    private function provider(): VaultReauthenticationProviderInterface
    {
        /** @var VaultReauthenticationProviderInterface asserted in the constructor */
        return $this->policy;
    }
}
