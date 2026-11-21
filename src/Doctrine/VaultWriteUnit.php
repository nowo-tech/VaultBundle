<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\VaultBundle\Internal\DeferredVaultEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * One vault write unit in one transaction (1.20, REQ-RUNTIME-003 rules 1-3), through nowo-tech/transaction-doctrine's
 * runner on the bundle's private entity manager ({@see VaultEntityManager}):
 * {@see DoctrineTransactionRunner::forEntityManager()} (a failed unit clears it, or the decorator recreates it when the
 * ORM closed it). An application manager is never flushed, cleared, closed or reset (since 2.0 the private manager is
 * the only mode: the 1.18 wiring on `database.entity_manager` was removed).
 *
 * Inside an application transaction the unit is a savepoint; the runner rolls back to it and repairs the nesting level
 * after a failure. A lock conflict leaves as nowo-tech/transaction-contract's TransientStorageFailure (409 + CONFLICT_*
 * on the bundle's routes). The vault change events dispatched during the unit (item, folder, tag, grant) are held
 * until it ends and dropped when it fails ({@see DeferredVaultEventDispatcher}).
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the private manager resolves its live instance on every call.
 *
 * @internal
 */
final readonly class VaultWriteUnit
{
    /**
     * @param EntityManagerInterface $entityManager the vault's private manager ({@see VaultEntityManager})
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?DeferredVaultEventDispatcher $events = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Runs the operation in one transaction, flushes the vault manager and commits.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $runner = DoctrineTransactionRunner::forEntityManager($this->entityManager, null, null, 1, $this->logger);

        if (!$this->events instanceof DeferredVaultEventDispatcher) {
            return $runner->run($operation);
        }

        return $this->events->deferWhile(static fn (): mixed => $runner->run($operation));
    }

    /**
     * Re-reads the item's row and locks it until the unit ends (`SELECT … FOR UPDATE`): a concurrent edit waits, then
     * sees the committed row. Call it inside {@see run()}.
     */
    public function lockForUpdate(object $entity): void
    {
        $this->entityManager->refresh($entity, LockMode::PESSIMISTIC_WRITE);
    }
}
