<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Database;

/**
 * Supported persistence backends for secure shares.
 */
final class DatabaseDriver
{
    public const DOCTRINE_ORM = 'doctrine_orm';

    public const DOCTRINE_MONGODB = 'doctrine_mongodb';

    public const CUSTOM = 'custom';

    public const PLATFORM_POSTGRESQL = 'postgresql';

    public const PLATFORM_MYSQL = 'mysql';

    public const PLATFORM_MARIADB = 'mariadb';

    public const PLATFORM_SQLITE = 'sqlite';

    public const PLATFORM_SQLSERVER = 'sqlserver';

    public const PLATFORM_ORACLE = 'oracle';

    public const PLATFORM_MONGODB = 'mongodb';

    public const PLATFORM_OTHER = 'other';

    /**
     * Returns every supported persistence driver identifier.
     *
     * @return list<string>
     */
    public static function drivers(): array
    {
        return [
            self::DOCTRINE_ORM,
            self::DOCTRINE_MONGODB,
            self::CUSTOM,
        ];
    }

    /**
     * Returns every supported database platform identifier.
     *
     * @return list<string>
     */
    public static function platforms(): array
    {
        return [
            self::PLATFORM_POSTGRESQL,
            self::PLATFORM_MYSQL,
            self::PLATFORM_MARIADB,
            self::PLATFORM_SQLITE,
            self::PLATFORM_SQLSERVER,
            self::PLATFORM_ORACLE,
            self::PLATFORM_MONGODB,
            self::PLATFORM_OTHER,
        ];
    }

    /**
     * Returns the relational (Doctrine ORM) database platform identifiers.
     *
     * @return list<string>
     */
    public static function relationalPlatforms(): array
    {
        return [
            self::PLATFORM_POSTGRESQL,
            self::PLATFORM_MYSQL,
            self::PLATFORM_MARIADB,
            self::PLATFORM_SQLITE,
            self::PLATFORM_SQLSERVER,
            self::PLATFORM_ORACLE,
            self::PLATFORM_OTHER,
        ];
    }

    /**
     * Resolves the effective persistence driver for a configured driver and platform.
     */
    public static function resolveDriver(string $driver, string $platform): string
    {
        if ($driver !== self::DOCTRINE_ORM) {
            return $driver;
        }

        if ($platform === self::PLATFORM_MONGODB) {
            return self::DOCTRINE_MONGODB;
        }

        return self::DOCTRINE_ORM;
    }
}
