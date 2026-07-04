<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Enum;

use Nowo\VaultBundle\Enum\VaultPermission;
use PHPUnit\Framework\TestCase;

final class VaultPermissionTest extends TestCase
{
    public function testWriteAndAdminCapabilities(): void
    {
        self::assertFalse(VaultPermission::Read->allowsWrite());
        self::assertTrue(VaultPermission::Write->allowsWrite());
        self::assertTrue(VaultPermission::Admin->allowsWrite());

        self::assertFalse(VaultPermission::Read->allowsAdmin());
        self::assertFalse(VaultPermission::Write->allowsAdmin());
        self::assertTrue(VaultPermission::Admin->allowsAdmin());
    }
}
