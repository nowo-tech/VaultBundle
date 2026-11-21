<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What the bundle asks for the vault features: the host's {@see VaultAccessPolicyInterface} (the configured
 * `security.access_checker`, the application's alias of the interface or the built-in), given the Symfony user the
 * vault works with as a {@see VaultUserRef}. Exceptions propagate: never an allow.
 *
 * Since 2.0 the only interface asked (the deprecated `VaultAccessCheckerInterface` was removed).
 *
 * @internal
 */
final readonly class VaultAccessCheckerGate
{
    /**
     * Wraps the access policy the build picked ({@see VaultPortsPass}).
     */
    public function __construct(private VaultAccessPolicyInterface $policy)
    {
    }

    /**
     * Whether the user may use the vault (null: the request's own token).
     */
    public function canAccess(?UserInterface $user = null): bool
    {
        return $this->policy->allowsAccess(VaultUserRefs::fromNullableUser($user));
    }

    /**
     * Whether the user may create vault items.
     */
    public function canCreate(?UserInterface $user = null): bool
    {
        return $this->policy->allowsCreate(VaultUserRefs::fromNullableUser($user));
    }

    /**
     * Whether the user may list vault items.
     */
    public function canList(?UserInterface $user = null): bool
    {
        return $this->policy->allowsList(VaultUserRefs::fromNullableUser($user));
    }

    /**
     * Whether the user may revoke shares.
     */
    public function canRevoke(?UserInterface $user = null): bool
    {
        return $this->policy->allowsRevoke(VaultUserRefs::fromNullableUser($user));
    }
}
