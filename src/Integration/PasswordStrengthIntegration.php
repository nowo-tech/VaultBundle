<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Integration;

/**
 * Detects whether PasswordStrengthBundle is installed at runtime.
 */
final class PasswordStrengthIntegration
{
    public const PASSWORD_STRENGTH_TYPE = 'Nowo\\PasswordStrengthBundle\\Form\\PasswordStrengthType';

    public const PASSWORD_STRENGTH_VALIDATOR = 'Nowo\\PasswordStrengthBundle\\Validator\\PasswordStrength';

    public static function isAvailable(): bool
    {
        return class_exists(self::PASSWORD_STRENGTH_TYPE);
    }
}
