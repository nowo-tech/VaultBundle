<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Database;

use Nowo\VaultBundle\Database\DatabaseDriver;
use PHPUnit\Framework\TestCase;

final class DatabaseDriverTest extends TestCase
{
    public function testResolveDriverUsesMongoWhenPlatformIsMongo(): void
    {
        self::assertSame(
            DatabaseDriver::DOCTRINE_MONGODB,
            DatabaseDriver::resolveDriver(DatabaseDriver::DOCTRINE_ORM, DatabaseDriver::PLATFORM_MONGODB),
        );
    }

    public function testDriversAndPlatforms(): void
    {
        self::assertCount(3, DatabaseDriver::drivers());
        self::assertCount(8, DatabaseDriver::platforms());
        self::assertContains(DatabaseDriver::DOCTRINE_ORM, DatabaseDriver::drivers());
        self::assertContains(DatabaseDriver::PLATFORM_MYSQL, DatabaseDriver::platforms());
        self::assertContains(DatabaseDriver::PLATFORM_POSTGRESQL, DatabaseDriver::relationalPlatforms());
        self::assertNotContains(DatabaseDriver::PLATFORM_MONGODB, DatabaseDriver::relationalPlatforms());
        self::assertSame(
            DatabaseDriver::DOCTRINE_ORM,
            DatabaseDriver::resolveDriver(DatabaseDriver::DOCTRINE_ORM, DatabaseDriver::PLATFORM_MYSQL),
        );
        self::assertSame(
            DatabaseDriver::CUSTOM,
            DatabaseDriver::resolveDriver(DatabaseDriver::CUSTOM, DatabaseDriver::PLATFORM_MYSQL),
        );
    }
}
