<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;
use function str_starts_with;
use function strtolower;

/**
 * Reports rolled-back transactions and savepoints (with their nesting level), released savepoints, commits and failed
 * commits to {@see VaultEntityManager}, which decides whether its identity map is stale (audit NJ-2).
 *
 * @internal
 */
final class PrivateEntityManagerConnection extends AbstractConnectionMiddleware
{
    /** `ROLLBACK TO SAVEPOINT x` (most platforms) and `ROLLBACK TRANSACTION x` (SQL Server). */
    private const SAVEPOINT_ROLLBACK = '/^\s*ROLLBACK\s+(?:TO\s+SAVEPOINT|TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    /** `RELEASE SAVEPOINT x`. */
    private const SAVEPOINT_RELEASE = '/^\s*RELEASE\s+SAVEPOINT\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    /** Savepoints of DBAL's nested transactions carry their nesting level. */
    private const NESTED = '/^DOCTRINE_?(\d+)$/i';

    /** Savepoints the vault opens around one bulk / DBAL statement (VaultSavepoint): never rows of the private manager. */
    private const VAULT_SAVEPOINT = 'nowo_vault_';

    /**
     * Wraps a driver connection of the private entity manager and reports its transaction outcome to the manager.
     */
    public function __construct(
        Connection $connection,
        private readonly VaultEntityManager $entityManager,
    ) {
        parent::__construct($connection);
    }

    /**
     * Commits, then tells the private manager the level is committed (a failed commit counts as a rollback).
     */
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            $this->entityManager->connectionRolledBack(1);

            throw $exception;
        }
        $this->entityManager->connectionCommitted();
    }

    /**
     * Rolls back and always tells the private manager that the outermost level was rolled back.
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->entityManager->connectionRolledBack(1);
        }
    }

    /**
     * Runs the statement and reports savepoint rollbacks and releases of other code to the private manager.
     */
    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_ROLLBACK, $sql, $match) === 1 && !str_starts_with(strtolower($match[1]), self::VAULT_SAVEPOINT)) {
            // A savepoint of unknown level (opened by the application itself): treated as undoing everything pending.
            $this->entityManager->connectionRolledBack(preg_match(self::NESTED, $match[1], $level) === 1 ? (int) $level[1] : null);
        } elseif (preg_match(self::SAVEPOINT_RELEASE, $sql, $match) === 1 && preg_match(self::NESTED, $match[1], $level) === 1) {
            $this->entityManager->savepointReleased((int) $level[1]);
        }

        return $result;
    }
}
