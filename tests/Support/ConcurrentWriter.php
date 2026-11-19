<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use RuntimeException;

use function fclose;
use function fgets;
use function fwrite;
use function is_resource;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_select;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * REQ-RUNTIME-003 race tests: a second writer on its own connection.
 *
 * - {@see hold()}: an open transaction on a second connection of this process (lock it, then let the request under
 *   test time out on it).
 * - {@see start()}: a separate PHP process (tests/Support/concurrent-writer.php) runs statements in one transaction,
 *   says READY, waits, then commits, so the request under test blocks on its locks and continues once it commits.
 *
 * Every session sets a short lock timeout (innodb_lock_wait_timeout / lock_timeout) so that a hang fails fast.
 */
final class ConcurrentWriter
{
    /** @var resource|null */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * A second connection of this process with the given lock timeout, already in a transaction.
     */
    public static function hold(string $url, int $lockTimeoutSeconds = 10): Connection
    {
        $connection = self::connect($url);
        self::setLockTimeout($connection, $lockTimeoutSeconds);
        $connection->beginTransaction();

        return $connection;
    }

    public static function connect(string $url): Connection
    {
        return DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url));
    }

    public static function setLockTimeout(Connection $connection, int $seconds): void
    {
        $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "SET lock_timeout = '" . $seconds . "s'"
            : 'SET SESSION innodb_lock_wait_timeout = ' . $seconds);
    }

    /**
     * Starts the writer process; returns once its statements ran (their locks are held) and it waits $commitAfter seconds.
     *
     * @param list<array{0: string, 1: list<mixed>}> $statements SQL and positional parameters
     */
    public static function start(string $url, array $statements, float $commitAfter): self
    {
        $writer          = new self();
        $writer->process = proc_open([PHP_BINARY, __DIR__ . '/../Support/concurrent-writer.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $writer->pipes);
        if (!is_resource($writer->process)) {
            throw new RuntimeException('Cannot start the concurrent writer.');
        }

        fwrite($writer->pipes[0], json_encode(['url' => $url, 'statements' => $statements, 'commit_after' => $commitAfter], JSON_THROW_ON_ERROR));
        fclose($writer->pipes[0]);

        $read   = [$writer->pipes[1]];
        $write  = null;
        $except = null;
        if (stream_select($read, $write, $except, 20) !== 1 || trim((string) fgets($writer->pipes[1])) !== 'READY') {
            $error = (string) fgets($writer->pipes[2]);
            $writer->finish();

            throw new RuntimeException('The concurrent writer did not get ready: ' . $error);
        }

        return $writer;
    }

    /**
     * Waits for the writer and returns its exit code (0: committed).
     */
    public function finish(): int
    {
        foreach ($this->pipes as $index => $pipe) {
            if ($index !== 0 && is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return is_resource($this->process) ? proc_close($this->process) : -1;
    }
}
