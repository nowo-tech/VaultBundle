<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\EventListener;

use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

use function max;

/**
 * Binds the bundle's private entity manager ({@see VaultEntityManager}, 1.19.0) to one unit of work, also when
 * the services resetter does not run between units of work (a host that removes or bypasses services_resetter,
 * `messenger:consume --no-reset`, long-running console processes; REQ-RUNTIME-002 rule 4). At each boundary the
 * private manager is dropped (its identity map cleared), so no vault row loaded for one request, message or
 * command is served to the next one, and a manager closed by a failed write is never reused:
 *
 * - HTTP: at the start of every main request (before controllers run) and at kernel.terminate. Sub-requests
 *   (fragments, ESI) share the unit of work of their main request;
 * - Messenger consumers: when a message is received and after it is handled or failed;
 * - console: when the OUTERMOST command starts and when it terminates, and only while no Messenger message is in
 *   flight. A command run in-process from another command or from a message handler must not drop the outer unit's
 *   pending vault changes: the nesting depth is counted on console.command / console.terminate, and the
 *   in-flight flag is set when a message is received and unset when it is handled, failed or the worker stops.
 *
 * Only the bundle's own manager is ever touched: the application's entity managers are never cleared here (that is
 * the host's job, REQ-RUNTIME-002 rule 7). The Messenger and console events are subscribed by name, so
 * symfony/messenger and symfony/console stay optional. The bundle wires the manager with an
 * IGNORE_ON_UNINITIALIZED_REFERENCE reference, so a boundary never builds it.
 */
final class VaultUnitOfWorkSubscriber implements EventSubscriberInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent';
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent';
    public const MESSENGER_WORKER_MESSAGE_FAILED   = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent';
    public const MESSENGER_WORKER_STOPPED          = 'Symfony\\Component\\Messenger\\Event\\WorkerStoppedEvent';

    /** Console event names (ConsoleEvents::COMMAND / TERMINATE), subscribed without requiring symfony/console. */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_TERMINATE = 'console.terminate';

    /**
     * Console commands currently running in this process (nested in-process runs count). Process-lifetime counter,
     * bounded by the nesting depth; intentionally not reset by kernel.reset.
     */
    private int $consoleDepth = 0;

    /** A Messenger message is being handled (between received and handled / failed / worker stopped). */
    private bool $messageInFlight = false;

    /**
     * @param iterable<ResetInterface> $resettables the private entity manager (when wired)
     */
    public function __construct(
        private readonly iterable $resettables = [],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', 4096],
            KernelEvents::TERMINATE                 => ['onTerminate', -4096],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', 4096],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', 4096],
            self::CONSOLE_TERMINATE                 => ['onConsoleTerminate', -4096],
        ];
    }

    /**
     * Start of a main request (sub-requests share their main request's unit of work).
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->clearState();
        }
    }

    /**
     * End of the main request (after the response was sent).
     */
    public function onTerminate(): void
    {
        $this->clearState();
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled or failed).
     */
    public function onMessageFinished(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clearState();
    }

    /**
     * The worker stopped (possibly after a skipped message): no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    /**
     * Start of a command: clears only when the outermost command starts outside a Messenger message.
     */
    public function onConsoleCommand(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        if (++$this->consoleDepth === 1 && !$this->messageInFlight) {
            $this->clearState();
        }
    }

    /**
     * End of a command: clears only when the outermost command ends outside a Messenger message.
     */
    public function onConsoleTerminate(): void
    {
        // @igor-ignore - Nesting counter of the running commands (no request data).
        $this->consoleDepth = max(0, $this->consoleDepth - 1);
        if ($this->consoleDepth === 0 && !$this->messageInFlight) {
            $this->clearState();
        }
    }

    /**
     * Drops the bundle's private entity manager (never an application manager).
     */
    public function clearState(): void
    {
        foreach ($this->resettables as $service) {
            $service->reset();
        }
    }
}
