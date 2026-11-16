<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Closure;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What the bundle asks for the vault features (1.19.0): the host's {@see VaultAccessPolicyInterface}, the deprecated
 * {@see VaultAccessCheckerInterface} (exactly as in 1.18), or both, which must then both allow (fail closed; the
 * deprecated one is asked first and a denial stops there). Exceptions propagate: never an allow.
 *
 * @internal
 */
final readonly class VaultAccessCheckerGate implements VaultAccessCheckerInterface
{
    /**
     * Selects the deprecated checker, the access policy or both (fail closed).
     */
    public function __construct(
        private string $mode,
        private ?VaultAccessCheckerInterface $legacy = null,
        private ?VaultAccessPolicyInterface $policy = null,
    ) {
        VaultPortMode::assert($mode, $legacy, $policy, 'access_checker');
    }

    /**
     * Whether the user may use the vault.
     */
    public function canAccess(?UserInterface $user = null): bool
    {
        return $this->decide($user, static fn (VaultAccessCheckerInterface $c): bool => $c->canAccess($user), static fn (VaultAccessPolicyInterface $p, ?VaultUserRef $ref): bool => $p->allowsAccess($ref));
    }

    /**
     * Whether the user may create vault items.
     */
    public function canCreate(?UserInterface $user = null): bool
    {
        return $this->decide($user, static fn (VaultAccessCheckerInterface $c): bool => $c->canCreate($user), static fn (VaultAccessPolicyInterface $p, ?VaultUserRef $ref): bool => $p->allowsCreate($ref));
    }

    /**
     * Whether the user may list vault items.
     */
    public function canList(?UserInterface $user = null): bool
    {
        return $this->decide($user, static fn (VaultAccessCheckerInterface $c): bool => $c->canList($user), static fn (VaultAccessPolicyInterface $p, ?VaultUserRef $ref): bool => $p->allowsList($ref));
    }

    /**
     * Whether the user may revoke shares.
     */
    public function canRevoke(?UserInterface $user = null): bool
    {
        return $this->decide($user, static fn (VaultAccessCheckerInterface $c): bool => $c->canRevoke($user), static fn (VaultAccessPolicyInterface $p, ?VaultUserRef $ref): bool => $p->allowsRevoke($ref));
    }

    /**
     * @param Closure(VaultAccessCheckerInterface): bool $legacy
     * @param Closure(VaultAccessPolicyInterface, ?VaultUserRef): bool $policy
     */
    private function decide(?UserInterface $user, Closure $legacy, Closure $policy): bool
    {
        if ($this->mode !== VaultPortMode::POLICY) {
            /** @var VaultAccessCheckerInterface $checker asserted in the constructor */
            $checker = $this->legacy;
            if ($legacy($checker) !== true) {
                return false;
            }
            if ($this->mode === VaultPortMode::LEGACY) {
                return true;
            }
        }

        /** @var VaultAccessPolicyInterface $accessPolicy asserted in the constructor */
        $accessPolicy = $this->policy;

        return $policy($accessPolicy, VaultUserRefs::fromNullableUser($user)) === true;
    }
}
