<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\VaultBundle\Internal\DeferredVaultEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * One vault write unit in one transaction (1.20, REQ-RUNTIME-003 rules 1-3), through nowo-tech/transaction-doctrine's
 * runner:
 *
 * - with the private entity manager (`database.private_entity_manager: true`) the manager is the bundle's own:
 *   {@see DoctrineTransactionRunner::forEntityManager()} (a failed unit clears it, or the decorator recreates it when the
 *   ORM closed it);
 * - in the deprecated 1.18 mode the manager is the application's (`database.entity_manager`):
 *   {@see DoctrineTransactionRunner::forHostEntityManager()}, which never closes, clears or resets an open host manager
 *   and replaces a closed one through the registry. It refuses to start (LogicException) while that manager holds
 *   changes the application did not flush, since a failed unit would otherwise drop them.
 *
 * Inside an application transaction the unit is a savepoint; the runner rolls back to it and repairs the nesting level
 * after a failure. A lock conflict leaves as nowo-tech/transaction-contract's TransientStorageFailure (409 + CONFLICT_*
 * on the bundle's routes). The vault change events dispatched during the unit (item, folder, tag, grant) are held
 * until it ends and dropped when it fails ({@see DeferredVaultEventDispatcher}).
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the manager is resolved on every call.
 *
 * @internal
 */
final readonly class VaultWriteUnit
{
    /**
     * @param EntityManagerInterface $entityManager the vault's manager: {@see VaultEntityManager}, or the application's one in the 1.18 mode
     * @param bool $privateManager whether $entityManager is the bundle's private manager
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private bool $privateManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
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
        $manager = $this->manager();
        $runner  = $this->privateManager
            ? DoctrineTransactionRunner::forEntityManager($manager, null, null, 1, $this->logger)
            : DoctrineTransactionRunner::forHostEntityManager($manager, $this->registry, $this->managerName, 1, $this->logger);

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
        $this->manager()->refresh($entity, LockMode::PESSIMISTIC_WRITE);
    }

    private function manager(): EntityManagerInterface
    {
        if ($this->privateManager || !$this->registry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }
}
