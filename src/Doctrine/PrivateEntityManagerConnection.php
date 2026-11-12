<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;

/**
 * Reports rolled-back transactions and savepoints (and failed commits) to {@see VaultEntityManager}.
 *
 * @internal
 */
final class PrivateEntityManagerConnection extends AbstractConnectionMiddleware
{
    /** `ROLLBACK TO SAVEPOINT x` (most platforms) and `ROLLBACK TRANSACTION x` (SQL Server). */
    private const SAVEPOINT_ROLLBACK = '/^\s*ROLLBACK\s+(?:TO\s+SAVEPOINT|TRAN(?:SACTION)?)\s+[A-Za-z0-9_]+\s*;?\s*$/i';

    public function __construct(
        Connection $connection,
        private readonly VaultEntityManager $entityManager,
    ) {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            $this->entityManager->connectionRolledBack();

            throw $exception;
        }
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->entityManager->connectionRolledBack();
        }
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_ROLLBACK, $sql) === 1) {
            $this->entityManager->connectionRolledBack();
        }

        return $result;
    }
}
