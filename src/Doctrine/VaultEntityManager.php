<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Closure;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\Parameter;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\EventListener\VaultUnitOfWorkSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function array_sum;
use function is_string;
use function ksort;
use function sprintf;

/**
 * The vault's private entity manager (1.19.0, REQ-ARCH-001 rule 6; `database.private_entity_manager`, on by default).
 *
 * Up to 1.18 the vault repositories and services wrote through the application's entity manager named by
 * `database.entity_manager` (default `default`): saving an item flushed every change the application had left pending
 * in that unit of work, a failed vault write closed it, and the vault reset it to recover. The vault now writes through a
 * **private** manager created on the connection, configuration (mapping, metadata cache, proxies, repository factory)
 * and event manager (table prefix, user mapping, host listeners) of that source manager. Its unit of work holds only
 * vault rows (and references of `user_class`): a vault flush never commits an application change, and a failed write
 * closes only this manager. The tables do not move. The connection is shared, so a transaction the application opened
 * around a vault call includes the vault's rows (its flushes then use savepoints).
 *
 * The object is a stable decorator (the repositories keep it); the wrapped manager is resolved on every call and
 * created again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) happened on the connection since it last wrote, so its identity map may
 *   describe rows that no longer exist ({@see PrivateEntityManagerMiddleware}). A stale manager that still has
 *   scheduled inserts, updates or deletes is **kept** (replacing it would silently drop them) until that work is
 *   flushed or the next unit-of-work boundary;
 * - {@see reset()} ran: at every unit-of-work boundary ({@see VaultUnitOfWorkSubscriber}: start and end of the main
 *   request, of a Messenger message and of the outermost console command) and at `kernel.reset`. Changes still pending
 *   at that moment are dropped and logged as a warning (entity classes and counts, never a value).
 *
 * The SQL filters enabled on the source manager, with their parameters, are mirrored on the private manager on every
 * call, and a filter whose parameters changed is re-created, so a parameter the source no longer has never survives.
 *
 * @internal
 */
final class VaultEntityManager extends EntityManagerDecorator implements ResetInterface
{
    private ?EntityManagerInterface $current = null;

    /** A rollback happened on the connection since the private manager was created or last replaced. */
    private bool $stale = false;

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry (the source manager is resolved per call)
     * @param string $sourceManagerName `nowo_vault.database.entity_manager` (the 1.18 manager, default `default`)
     * @param LoggerInterface|null $logger warns when a boundary drops pending changes
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $sourceManagerName = 'default',
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();
            // @igor-ignore - Bundle-owned unit of work: dropped at every boundary, replaced when closed, stale or on another connection.
            $current = $this->current = new EntityManager($source->getConnection(), $source->getConfiguration(), $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * Whether a private manager exists right now (no side effect; tests and the boundary subscriber).
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect; tests).
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * A transaction or savepoint was rolled back on the connection (told by {@see PrivateEntityManagerConnection}).
     * The manager is replaced at its next use unless it has scheduled work; it is never cleared here (the rollback
     * may run inside the private manager's own failing flush).
     */
    public function connectionRolledBack(): void
    {
        if ($this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one.
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager (a unit-of-work boundary or `kernel.reset`); the next call creates a fresh one.
     * Changes still pending are lost: they are logged as a warning with their entity classes and counts.
     */
    public function reset(): void
    {
        $current = $this->current;
        // Dropped first, so a failure below can never keep a manager across the boundary (audit NF2-2).
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen()) {
            return;
        }

        try {
            $this->warnAboutDroppedChanges($current);
        } catch (Throwable $exception) {
            // Computing the change sets can throw (invalid association, detached or half-built entity).
            $this->logGenericWarning($exception);
        }

        try {
            $current->clear();
        } catch (Throwable $exception) {
            // A host onClear listener that throws must not abort the boundary (audit NF3-2): the manager is dropped.
            $this->logGenericWarning($exception);
        }
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset by the bundle.
     *
     * @throws LogicException when the source is not an ORM manager or does not map the vault entities
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->sourceManagerName);
        if (!$source instanceof EntityManagerInterface || $source->getMetadataFactory()->isTransient(VaultItem::class)) {
            throw new LogicException(sprintf('The Doctrine entity manager "%s" (nowo_vault.database.entity_manager) does not map %s: map the vault entities on it (see docs/CONFIGURATION.md, "Database").', $this->sourceManagerName, VaultItem::class));
        }

        return $source;
    }

    /**
     * The fallback warning of {@see reset()}; a failing logger never breaks the boundary.
     */
    private function logGenericWarning(Throwable $exception): void
    {
        try {
            $this->logger?->warning('Vault: the bundle\'s private entity manager was dropped at a unit-of-work boundary; its pending changes could not be inspected or cleared ({exception}).', [
                'bundle'    => 'nowo_vault',
                'exception' => $exception::class,
            ]);
        } catch (Throwable) {
            // Nothing else to report to: the manager is dropped anyway.
        }
    }

    /**
     * Safety net (audits NF-2, ND-L2): a boundary that drops the private manager while it still has inserts, updates or
     * deletes waiting (a vault entity changed and never saved through the vault) logs them, per entity class.
     */
    private function warnAboutDroppedChanges(EntityManagerInterface $manager): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                $class           = VaultManagerRegistry::realClass($entity::class);
                $changes[$class] = ($changes[$class] ?? 0) + 1;
            }
        }
        if ($changes === []) {
            return;
        }

        ksort($changes);
        $this->logger->warning('Vault: {count} pending change(s) of the bundle\'s private entity manager were dropped at a unit-of-work boundary. Write vault rows through the vault services or repositories (save() / remove()); the application\'s flush() does not write them.', [
            'bundle'  => 'nowo_vault',
            'count'   => array_sum($changes),
            'changes' => $changes,
        ]);
    }

    /**
     * Scheduled inserts, updates or deletes, including changes not computed yet (a managed entity edited in memory).
     */
    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }

    /**
     * Mirrors the SQL filters enabled on the source manager, with their parameters, on the private one, and disables
     * the others. A filter whose parameters differ (its state string) is disabled and enabled again (a fresh instance)
     * before its parameters are copied, so a parameter the source no longer has (a previous tenant) never survives.
     * The parameters are read as they are stored (audit NF-7): values, list flag and the list type are kept.
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                $filters->disable($name);
            }
            $copy = $filters->enable($name);
            foreach ($this->filterParameters($filter) as $parameter => $value) {
                if ($value->isList) {
                    /** @var array<mixed> $list */
                    $list = $value->value;
                    $copy->setParameterList($parameter, $list, is_string($value->type) ? $value->type : Types::STRING);
                } else {
                    // SQLFilter::setParameter() takes a string type or null: a non-string type was inferred, null infers it again.
                    $copy->setParameter($parameter, $value->value, is_string($value->type) ? $value->type : null);
                }
            }
        }
    }

    /**
     * The raw parameters of a filter (SQLFilter keeps them private and only exposes quoted values).
     *
     * @return array<string, Parameter>
     */
    private function filterParameters(SQLFilter $filter): array
    {
        $read = static fn (SQLFilter $filter): array => $filter->parameters; // @phpstan-ignore property.private (bound to SQLFilter below)
        /** @var array<string, Parameter> $parameters */
        $parameters = Closure::bind($read, null, SQLFilter::class)($filter);

        return $parameters;
    }
}
