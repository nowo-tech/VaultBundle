<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

/**
 * Counts the SQL statements sent through DBAL (scopes test kernel): the constant-query tests of the catalogue.
 */
final class QueryCounterMiddleware implements Middleware
{
    public static int $count = 0;

    /** @var list<string> */
    public static array $sql = [];

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        QueryCounterMiddleware::record($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        QueryCounterMiddleware::record($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        QueryCounterMiddleware::record($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public static function record(string $sql): void
    {
        ++self::$count;
        self::$sql[] = $sql;
    }

    public static function reset(): void
    {
        self::$count = 0;
        self::$sql   = [];
    }
}
