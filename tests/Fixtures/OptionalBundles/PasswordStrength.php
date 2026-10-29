<?php

declare(strict_types=1);

namespace Nowo\PasswordStrengthBundle\Validator;

use Symfony\Component\Validator\Constraint;

use function is_array;

/**
 * Minimal stand-in for the PasswordStrength constraint: accepts the legacy options array (< 2.8) and the
 * named arguments (>= 2.8) so both VaultItemFormType branches can be asserted.
 */
final class PasswordStrength extends Constraint
{
    public ?string $policyMode = null;
    public ?string $level      = null;
    public bool $legacyOptions = false;

    /**
     * @param array{policyMode?: string, level?: string}|null $options
     */
    public function __construct(?array $options = null, ?string $policyMode = null, ?string $level = null)
    {
        parent::__construct(null);
        $this->legacyOptions = is_array($options);
        $this->policyMode    = $options['policyMode'] ?? $policyMode;
        $this->level         = $options['level'] ?? $level;
    }
}
