<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\ValueObject\VaultOwner;

/**
 * Password policy per owner (1.16.0, `health.policy_resolver`): e.g. per organization. Null = the configured default.
 */
interface VaultPasswordPolicyResolverInterface
{
    /**
     * The policy of the owner, or null for the default `health.policy`.
     */
    public function forOwner(VaultOwner $owner): ?VaultPasswordPolicy;
}
