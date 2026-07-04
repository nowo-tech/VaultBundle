<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Integration;

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
}
