<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Event\VaultTagChangeEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_splice;
use function count;
use function max;

/**
 * The event dispatcher of the vault's write services (1.20, REQ-RUNTIME-003 rule 1): while a vault write unit runs
 * ({@see deferWhile()}), the change notifications (ITEM_CHANGED, FOLDER_CHANGED, TAG_CHANGED, GRANT_*) are held and
 * dispatched once the outermost unit has committed, in their order; the ones of a failed unit are dropped. Every other
 * event (access checks, vetoes, list queries) is dispatched at once, since its listeners answer the caller.
 * Outside a unit everything is dispatched at once, as before.
 *
 * The queue lives only for the length of one unit and is emptied in every case; {@see reset()} also empties it at
 * `kernel.reset` (FrankenPHP worker mode).
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
    ];

    /** @var list<array{object, string|null}> */
    private array $queue = [];

    private int $depth = 0;

    public function __construct(
        private readonly EventDispatcherInterface $inner,
    ) {
    }

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if ($this->depth > 0 && $this->isDeferred($event)) {
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at kernel.reset.
            $this->queue[] = [$event, $eventName];

            return $event;
        }

        return $this->inner->dispatch($event, $eventName);
    }

    /**
     * Runs the unit with the change notifications held; dispatches them after the outermost unit returned.
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
        // @igor-ignore - Held only while a write unit runs; emptied when it ends and at kernel.reset.
        ++$this->depth;

        try {
            $result = $unit();
        } catch (Throwable $throwable) {
            // The unit rolled back: its notifications never happened.
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at kernel.reset.
            array_splice($this->queue, $mark);

            throw $throwable;
        } finally {
            // A reset() inside the unit already set it to zero.
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at kernel.reset.
            $this->depth = max(0, $this->depth - 1);
        }

        if ($this->depth === 0) {
            $queue = $this->queue;
            // @igor-ignore - Held only while a write unit runs; emptied when it ends and at kernel.reset.
            $this->queue = [];
            foreach ($queue as [$event, $eventName]) {
                $this->inner->dispatch($event, $eventName);
            }
        }

        return $result;
    }

    public function reset(): void
    {
        // @igor-ignore - Held only while a write unit runs; emptied when it ends and at kernel.reset.
        $this->queue = [];
        $this->depth = 0;
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
