<?php

declare(strict_types=1);

namespace Nowo\PasswordStrengthBundle\Model;

/**
 * Minimal stand-in for nowo-tech/password-strength-bundle's PasswordConditions (not installed in this repo), loaded
 * only by tests that run in a separate process.
 */
final readonly class PasswordConditions
{
    public function __construct(
        public int $minLength = 0,
    ) {
    }
}
