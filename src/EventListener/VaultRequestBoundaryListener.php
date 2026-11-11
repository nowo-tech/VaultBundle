<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\EventListener;

use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Clears the bundle's request-scoped memos at every request / message / command boundary (1.17.1, REQ-RUNTIME-002).
 *
 * FrankenPHP worker mode with `FRANKENPHP_RESET_KERNEL=false` keeps the container alive and may never run the
 * services resetter (`kernel.reset`). The scope membership memo ({@see RequestScopedScopeAccessCache}) must still be
 * empty when the next request starts, otherwise a revoked team or scope membership keeps granting access in that
 * worker. This listener empties it on the main `kernel.request` (before the firewall and the controllers), on
 * `kernel.terminate`, on `console.terminate` and on Messenger's `WorkerMessageHandledEvent` /
 * `WorkerMessageFailedEvent`. Sub-requests keep the memo of their main request. The events are wired by name in
 * {@see VaultExtension}, so Console and Messenger stay optional.
 */
final readonly class VaultRequestBoundaryListener
{
    /** Messenger: a consumer finished one message (class name; Messenger is optional). */
    public const WORKER_MESSAGE_HANDLED = 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent';

    /** Messenger: a consumer failed one message (class name; Messenger is optional). */
    public const WORKER_MESSAGE_FAILED = 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent';

    /**
     * Creates the listener around the memo it clears.
     */
    public function __construct(
        private RequestScopedScopeAccessCache $scopeAccess,
    ) {
    }

    /**
     * Main `kernel.request`: start every request with an empty memo (sub-requests keep their main request's memo).
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->scopeAccess->reset();
    }

    /**
     * `kernel.terminate`, `console.terminate` and the Messenger worker events: forget the memo of the unit that ended.
     */
    public function onBoundary(): void
    {
        $this->scopeAccess->reset();
    }
}
