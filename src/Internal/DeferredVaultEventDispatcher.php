<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Nowo\VaultBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\VaultBundle\Event\VaultExtensionTokenRevokedEvent;
use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Event\VaultPersonalDataErasedEvent;
use Nowo\VaultBundle\Event\VaultTagChangeEvent;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_shift;
use function array_splice;
use function count;
use function max;

/**
 * The event dispatcher of the vault's write services. It holds the change notifications (ITEM_CHANGED, FOLDER_CHANGED,
 * TAG_CHANGED, GRANT_*, and since 2.5.0 PERSONAL_DATA_ERASED and EXTENSION_TOKEN_REVOKED) until the data they describe is committed (REQ-ARCH-001 rule 7, REQ-RUNTIME-003 rule 1); every
 * other event (access checks, vetoes, list queries) is dispatched at once, since its listeners answer the caller.
 *
 * - **Vault write unit** (1.20, {@see deferWhile()}): the notifications of the unit are queued and released once the
 *   outermost unit has returned; those of a failed unit are dropped.
 * - **Application transaction** (2.1.0, DDD-host checklist B4): when the vault's connection is still inside a
 *   transaction at that point (the application opened it, so the vault's unit was only a savepoint), or when a write
 *   service runs outside a unit inside such a transaction, the notifications are **held** at the current nesting level
 *   until the application's outermost commit. A rollback of that level (or of the transaction) drops them; a released
 *   savepoint moves them to the level below. The connection reports these outcomes
 *   ({@see PrivateEntityManagerConnection}, doctrine/dbal 4). Committed notifications are dispatched at the next idle
 *   point: the next vault notification or unit outside a transaction, or the end of the request, message or command
 *   ({@see DeferredVaultEventBoundaryListener}); listeners never run inside the connection's commit call. Without the
 *   connection hook (doctrine/dbal 3) nothing is held: the 1.20 behaviour.
 *
 * FrankenPHP worker mode (kernel reset off): everything held is request state. The boundary listener releases what was
 * committed and drops the rest at the end of every request / message / command, and empties everything at the start of
 * the next main request; {@see reset()} also empties it at `kernel.reset`.
 *
 * @internal
 */
final class DeferredVaultEventDispatcher implements EventDispatcherInterface, ResetInterface
{
    private const DEFERRED = [
        VaultItemChangeEvent::class,
        VaultFolderChangeEvent::class,
        VaultTagChangeEvent::class,
        VaultGrantChangeEvent::class,
        // 2.5.0: an erasure that joined UserKit's (or the application's) transaction is announced after its commit.
        VaultPersonalDataErasedEvent::class,
        VaultExtensionTokenRevokedEvent::class,
    ];

    /** @var list<array{object, string|null}> notifications of the running vault unit */
    private array $queue = [];

    private int $depth = 0;

    /** @var list<array{int, object, string|null}> notifications waiting for the application's commit: [level, event, name] */
    private array $held = [];

    /** @var list<array{object, string|null}> committed notifications waiting for an idle point */
    private array $committed = [];

    private bool $releasing = false;

    /** Depth of {@see holdForHostWhile()} calls (2.5.0): notifications are held for the application's commit. */
    private int $forcedHold = 0;

    /**
     * @param VaultTransactionLevelInterface|null $levels the vault connection's nesting level; null when the connection
     *                                                    does not report its commits (nothing is then held)
     */
    public function __construct(
        private readonly EventDispatcherInterface $inner,
        private readonly ?VaultTransactionLevelInterface $levels = null,
        private readonly ?LoggerInterface $logger = null,
        /** `events.after_host_commit` (2.1.0); without it only {@see holdForHostWhile()} holds (2.5.0). */
        private readonly bool $holdForHost = true,
    ) {
    }

    /**
     * Runs the work with every notification it dispatches held for the application's outermost commit, whatever
     * `events.after_host_commit` says (2.5.0, audit ZCM-L1: the personal-data erasure that joined UserKit's transaction).
     * Without the connection hook (doctrine/dbal 3) nothing can be held: the notifications leave at once.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function holdForHostWhile(callable $work): mixed
    {
        // @igor-ignore - Per-call depth counter, always restored in finally.
        ++$this->forcedHold;
        try {
            return $work();
        } finally {
            // @igor-ignore - Per-call depth counter, always restored in finally.
            $this->forcedHold = max(0, $this->forcedHold - 1);
        }
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if (!$this->isDeferred($event)) {
            return $this->inner->dispatch($event, $eventName);
        }

        if ($this->depth > 0) {
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at every boundary.
            $this->queue[] = [$event, $eventName];

            return $event;
        }

        $level = $this->holdingLevel();
        if ($level > 0) {
            // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
            $this->held[] = [$level, $event, $eventName];

            return $event;
        }

        $this->releaseCommitted();

        return $this->inner->dispatch($event, $eventName);
    }

    /**
     * Runs the unit with the change notifications held; releases them after the outermost unit returned (or holds them
     * until the application's commit, see the class comment).
     *
     * @template T
     *
     * @param callable(): T $unit
     *
     * @return T
     */
    public function deferWhile(callable $unit): mixed
    {
        $mark = count($this->queue);
        // @igor-ignore - Held only while a write unit runs; emptied when it ends and at every boundary.
        ++$this->depth;

        try {
            $result = $unit();
        } catch (Throwable $throwable) {
            // The unit rolled back: its notifications never happened.
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at every boundary.
            array_splice($this->queue, $mark);

            throw $throwable;
        } finally {
            // A reset() inside the unit already set it to zero.
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at every boundary.
            $this->depth = max(0, $this->depth - 1);
        }

        if ($this->depth === 0) {
            $queue = $this->queue;
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at every boundary.
            $this->queue = [];
            $level       = $this->holdingLevel();
            if ($level > 0) {
                foreach ($queue as [$event, $eventName]) {
                    // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
                    $this->held[] = [$level, $event, $eventName];
                }
            } else {
                $this->releaseCommitted();
                foreach ($queue as [$event, $eventName]) {
                    $this->inner->dispatch($event, $eventName);
                }
            }
        }

        return $result;
    }

    /**
     * The application's outermost transaction committed: every held notification is committed and waits for the next
     * idle point (never dispatched inside the connection's commit call).
     */
    public function connectionCommitted(): void
    {
        foreach ($this->held as [, $event, $eventName]) {
            // @igor-ignore - Committed notifications waiting for an idle point; released or dropped at every boundary.
            $this->committed[] = [$event, $eventName];
        }
        // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
        $this->held = [];
    }

    /**
     * A transaction (level 1, also a failed commit) or a savepoint (its level; null when unknown: everything) was rolled
     * back: the notifications held at that level or deeper are dropped.
     */
    public function connectionRolledBack(?int $level = 1): void
    {
        $kept = [];
        foreach ($this->held as $entry) {
            if ($level !== null && $entry[0] < $level) {
                $kept[] = $entry;
            }
        }
        // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
        $this->held = $kept;
    }

    /**
     * A savepoint of this level was released: its notifications now belong to the level below.
     */
    public function savepointReleased(int $level): void
    {
        foreach ($this->held as $index => $entry) {
            if ($entry[0] === $level && $level > 1) {
                // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
                $this->held[$index][0] = $level - 1;
            }
        }
    }

    /**
     * End of a request, message or command: dispatches the committed notifications and drops the ones whose
     * transaction never committed (logged as a warning: the data they describe is not in the database).
     */
    public function boundary(): void
    {
        $this->releaseCommitted();
        $this->dropHeld();
    }

    /**
     * How many notifications wait (held for a commit, or committed and waiting for an idle point).
     */
    public function pendingCount(): int
    {
        return count($this->held) + count($this->committed);
    }

    public function reset(): void
    {
        // @igor-ignore - Held only while a write unit runs; emptied when it ends and at every boundary.
        $this->queue = [];
        $this->depth = 0;
        // @igor-ignore - Per-call depth counter, always restored in finally.
        $this->forcedHold = 0;
        $this->dropHeld();
        // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
        $this->held = [];
        if ($this->committed !== []) {
            $this->logger?->warning('nowo_vault: dropped {count} committed change notification(s) that were never dispatched.', ['count' => count($this->committed)]);
        }
        // @igor-ignore - Committed notifications waiting for an idle point; released or dropped at every boundary.
        $this->committed = [];
    }

    /**
     * Dispatches the committed notifications, in their order. The data is committed, so a failing listener is logged
     * and never reaches the vault call or the boundary that released it.
     */
    private function releaseCommitted(): void
    {
        if ($this->releasing) {
            return;
        }

        // @igor-ignore - Re-entrancy flag of the release loop; always cleared in finally.
        $this->releasing = true;
        try {
            while ($this->committed !== []) {
                // @igor-ignore - Committed notifications waiting for an idle point; released or dropped at every boundary.
                [$event, $eventName] = array_shift($this->committed);
                try {
                    $this->inner->dispatch($event, $eventName);
                } catch (Throwable $exception) {
                    $this->logger?->error('nowo_vault: a listener of a committed change notification failed: {class}', ['class' => $exception::class, 'exception' => $exception]);
                }
            }
        } finally {
            // @igor-ignore - Re-entrancy flag of the release loop; always cleared in finally.
            $this->releasing = false;
        }
    }

    private function dropHeld(): void
    {
        if ($this->held !== []) {
            $this->logger?->warning('nowo_vault: dropped {count} change notification(s) of a transaction that did not commit.', ['count' => count($this->held)]);
        }
        // @igor-ignore - Held until the application's commit; dropped on rollback and at every boundary.
        $this->held = [];
    }

    /**
     * The transaction level to hold a notification at, or 0 to dispatch it now.
     */
    private function holdingLevel(): int
    {
        return $this->holdForHost || $this->forcedHold > 0 ? $this->level() : 0;
    }

    private function level(): int
    {
        return $this->levels?->transactionLevel() ?? 0;
    }

    private function isDeferred(object $event): bool
    {
        foreach (self::DEFERRED as $class) {
            if ($event instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
