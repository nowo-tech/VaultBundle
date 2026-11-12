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
     * @param object|null $manager the vault manager the statement belongs to: the private manager records the
     *                             transaction level the write now waits in (audit NJV-1)
     *
     * @return T
     */
    public static function write(Connection $connection, Closure $write, ?object $manager = null): mixed
    {
        if (!$connection->isTransactionActive()) {
            return $write();
        }

        $result = self::inSavepoint($connection, $write);
        if ($manager instanceof VaultEntityManager) {
            $manager->noteWrite();
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private static function inSavepoint(Connection $connection, Closure $write): mixed
    {

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
