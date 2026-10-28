<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Integration;

use Composer\InstalledVersions;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use PHPUnit\Framework\TestCase;

final class PasswordStrengthIntegrationTest extends TestCase
{
    public function testAvailabilityMatchesInstalledPackage(): void
    {
        self::assertSame(
            class_exists(PasswordStrengthIntegration::PASSWORD_STRENGTH_TYPE),
            PasswordStrengthIntegration::isAvailable(),
        );
    }

    public function testUiTranslationDomainNeedsVersion28(): void
    {
        self::assertFalse(PasswordStrengthIntegration::versionSupportsUiTranslationDomain(null));
        self::assertFalse(PasswordStrengthIntegration::versionSupportsUiTranslationDomain('dev-main'));
        self::assertFalse(PasswordStrengthIntegration::versionSupportsUiTranslationDomain('1.3.0.0'));
        self::assertFalse(PasswordStrengthIntegration::versionSupportsUiTranslationDomain('2.7.9.0'));
        self::assertTrue(PasswordStrengthIntegration::versionSupportsUiTranslationDomain('2.8.0.0'));
        self::assertTrue(PasswordStrengthIntegration::versionSupportsUiTranslationDomain('v2.10.0'));
        self::assertTrue(PasswordStrengthIntegration::versionSupportsUiTranslationDomain('3.0.0.0'));
    }

    public function testSupportsUiTranslationDomainFollowsTheInstalledPackage(): void
    {
        self::assertSame(
            InstalledVersions::isInstalled(PasswordStrengthIntegration::PACKAGE)
                && PasswordStrengthIntegration::versionSupportsUiTranslationDomain(InstalledVersions::getVersion(PasswordStrengthIntegration::PACKAGE)),
            PasswordStrengthIntegration::supportsUiTranslationDomain(),
        );
    }
}
