<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Integration;

use Nowo\VaultBundle\Integration\DoctrineEncryptIntegration;
use PHPUnit\Framework\TestCase;

final class DoctrineEncryptIntegrationTest extends TestCase
{
    public function testAvailabilityMatchesInstalledPackage(): void
    {
        self::assertSame(
            class_exists(DoctrineEncryptIntegration::ENCRYPTED_ATTRIBUTE),
            DoctrineEncryptIntegration::isAvailable(),
        );
    }
}
