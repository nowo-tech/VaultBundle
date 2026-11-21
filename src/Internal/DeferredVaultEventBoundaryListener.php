<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Request / message / command boundaries of the held change notifications (2.1.0, {@see DeferredVaultEventDispatcher}):
 *
 * - main `kernel.request`: everything is emptied (nothing of an earlier request may be dispatched in this one);
 * - main `kernel.finish_request`, `kernel.terminate`, `console.terminate` and Messenger's `WorkerMessageHandledEvent` /
 *   `WorkerMessageFailedEvent`: the committed notifications are dispatched and the uncommitted ones dropped.
 *
 * Sub-requests run inside their main request and change nothing. The events are wired by name in the extension, so
 * Console and Messenger stay optional. The dispatcher is referenced while uninitialised only: a boundary never builds
 * it.
 *
 * @internal
 */
final readonly class DeferredVaultEventBoundaryListener
{
    /**
     * @param iterable<DeferredVaultEventDispatcher> $dispatchers
     */
    public function __construct(
        private iterable $dispatchers,
    ) {
    }

    /**
     * Main `kernel.request`: starts with nothing held.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        foreach ($this->dispatchers as $dispatcher) {
            $dispatcher->reset();
        }
    }

    /**
     * Main `kernel.finish_request` (a sub-request may finish inside its main request's transaction).
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->onBoundary();
        }
    }

    /**
     * `kernel.terminate`, `console.terminate` and the Messenger worker events.
     */
    public function onBoundary(): void
    {
        foreach ($this->dispatchers as $dispatcher) {
            $dispatcher->boundary();
        }
    }
}
