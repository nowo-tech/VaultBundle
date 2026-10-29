<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Integration;

use Composer\InstalledVersions;

use function preg_match;
use function version_compare;

/**
 * Detects whether PasswordStrengthBundle is installed at runtime.
 */
final class PasswordStrengthIntegration
{
    public const PASSWORD_STRENGTH_TYPE = 'Nowo\\PasswordStrengthBundle\\Form\\PasswordStrengthType';

    public const PASSWORD_STRENGTH_VALIDATOR = 'Nowo\\PasswordStrengthBundle\\Validator\\PasswordStrength';

    public const PACKAGE = 'nowo-tech/password-strength-bundle';

    /**
     * Whether nowo-tech/password-strength-bundle is installed.
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::PASSWORD_STRENGTH_TYPE);
    }

    /**
     * PasswordStrengthBundle >= 2.8 has the `ui_translation_domain` option (widget copy in its own domain) and a
     * constraint with named arguments.
     */
    public static function supportsUiTranslationDomain(): bool
    {
        return self::versionSupportsUiTranslationDomain(
            InstalledVersions::isInstalled(self::PACKAGE) ? InstalledVersions::getVersion(self::PACKAGE) : null,
        );
    }

    /**
     * Whether the given Composer version string is PasswordStrengthBundle 2.8 or newer.
     *
     * @param string|null $version Installed version as reported by Composer (e.g. `2.10.0.0`, `dev-main`)
     */
    public static function versionSupportsUiTranslationDomain(?string $version): bool
    {
        if ($version === null || !preg_match('/^v?(\d+\.\d+(?:\.\d+)*)/', $version, $matches)) {
            return false;
        }

        return version_compare($matches[1], '2.8', '>=');
    }
}
