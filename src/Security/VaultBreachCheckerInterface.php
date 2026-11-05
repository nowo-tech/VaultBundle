<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use SensitiveParameter;

/**
 * Host hook (1.16.0, opt-in `health.breach_checker`): whether a password appears in a breach corpus. Implement it with
 * a k-anonymity range API (send a 5-character SHA-1 prefix, never the password or its full hash).
 */
interface VaultBreachCheckerInterface
{
    /**
     * True when breached, false when not, null when unknown (the service failed).
     */
    public function isBreached(#[SensitiveParameter] string $password): ?bool;
}
