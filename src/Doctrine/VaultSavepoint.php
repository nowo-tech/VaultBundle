<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * Runs one vault DBAL / bulk DQL write inside a savepoint when the application already opened a transaction on the
 * connection (1.19.0, REQ-ARCH-001 rule 6): a failing vault statement then rolls back to the savepoint only, so it
 * never leaves the application's transaction aborted (PostgreSQL) and the application's own COMMIT keeps its rows
 * (audit NC2-1 class). Outside a transaction, or on a platform without savepoints, the write runs as before.
 *
 * @internal
 */
final class VaultSavepoint
{
    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    public static function write(Connection $connection, Closure $write): mixed
    {
        if (!$connection->isTransactionActive()) {
            return $write();
        }

        $platform = $connection->getDatabasePlatform();
        if (!$platform->supportsSavepoints()) {
            return $write(); // @codeCoverageIgnore
        }

        $name = 'nowo_vault_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($name);
        try {
            $result = $write();
        } catch (Throwable $exception) {
            $connection->rollbackSavepoint($name);

            throw $exception;
        }
        if ($platform->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($name);
        }

        return $result;
    }
}
