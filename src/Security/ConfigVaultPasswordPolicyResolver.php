<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\ValueObject\VaultOwner;

/**
 * Default policy resolver (1.16.0): `health.policy` for every owner.
 */
final readonly class ConfigVaultPasswordPolicyResolver implements VaultPasswordPolicyResolverInterface
{
    /**
     * Stores the configured policy.
     */
    public function __construct(
        private VaultPasswordPolicy $policy = new VaultPasswordPolicy(),
    ) {
    }

    /**
     * The configured policy.
     */
    public function forOwner(VaultOwner $owner): VaultPasswordPolicy
    {
        return $this->policy;
    }
}
