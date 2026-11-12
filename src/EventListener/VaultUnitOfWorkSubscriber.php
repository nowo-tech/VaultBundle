<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\EventListener;

use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;
use WeakMap;

use function array_slice;
use function count;
use function is_object;
use function max;
use function method_exists;

use const PHP_INT_MAX;

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
 *   pending vault changes: each run is a frame (command + input) pushed at console.command and removed, with
 *   the frames above it, at its console.terminate (first listeners), restoring the message / request state it saved;
 *   a frame whose console.error listener threw (no terminate) is dropped at the next main request, message, command
 *   or worker stop, so listeners that throw or stop the propagation cannot leave the tracking behind (audit NM). The
 *   in-flight flag is set when a message is received and unset when it is handled, failed, skipped or the worker
 *   stops; main requests are counted.
 *
 * Only the bundle's own manager is ever touched: the application's entity managers are never cleared here (that is
 * the host's job, REQ-RUNTIME-002 rule 7). The Messenger and console events are subscribed by name, so
 * symfony/messenger and symfony/console stay optional. The bundle wires the manager with an
 * IGNORE_ON_UNINITIALIZED_REFERENCE reference, so a boundary never builds it.
 */
final class VaultUnitOfWorkSubscriber implements EventSubscriberInterface, ResetInterface
{
    /** Messenger event names (class names), subscribed without requiring symfony/messenger. */
    public const MESSENGER_WORKER_MESSAGE_RECEIVED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageReceivedEvent';
    public const MESSENGER_WORKER_MESSAGE_HANDLED  = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent';
    public const MESSENGER_WORKER_MESSAGE_FAILED   = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent';
    /** Dispatched only by `messenger:failed:retry` when a message is skipped: the message unit ends too. */
    public const MESSENGER_WORKER_MESSAGE_SKIPPED = 'Symfony\\Component\\Messenger\\Event\\WorkerMessageSkipEvent';
    public const MESSENGER_WORKER_STOPPED         = 'Symfony\\Component\\Messenger\\Event\\WorkerStoppedEvent';

    /** Console event names (ConsoleEvents::COMMAND / TERMINATE / ERROR), subscribed without requiring symfony/console. */
    public const CONSOLE_COMMAND   = 'console.command';
    public const CONSOLE_TERMINATE = 'console.terminate';
    public const CONSOLE_ERROR     = 'console.error';

    /**
     * Running console commands, outermost first (audit NM, revision 2). A frame is one run, keyed by the command AND
     * its input (a command that runs itself in-process gets another input per run); it saves the message / request
     * state of the moment it started, restored when it is removed. "Outermost" = no frame, no message and no main
     * request in flight when it started.
     *
     * @var list<array{command: object, input: object|null, outermost: bool, failing: bool, message: bool, requests: int}>
     */
    private array $frames = [];

    /** A Messenger message is being handled (between received and handled / failed / skipped / worker stopped). */
    private bool $messageInFlight = false;

    /** Main requests in flight (a counter: a main request handled inside another one does not end the outer one). */
    private int $requestsInFlight = 0;

    /** @var WeakMap<object, true> console terminate events whose late listener still has to clear */
    private WeakMap $pendingClear;

    /**
     * @param iterable<ResetInterface> $resettables the private entity manager (when wired)
     */
    public function __construct(
        private readonly iterable $resettables = [],
    ) {
        $this->pendingClear = new WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                   => ['onRequest', 4096],
            KernelEvents::FINISH_REQUEST            => ['onFinishRequest', PHP_INT_MAX],
            KernelEvents::TERMINATE                 => [['onTerminateBookkeeping', PHP_INT_MAX], ['onTerminate', -4096]],
            self::MESSENGER_WORKER_MESSAGE_RECEIVED => ['onMessageReceived', PHP_INT_MAX],
            self::MESSENGER_WORKER_MESSAGE_HANDLED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_FAILED   => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_MESSAGE_SKIPPED  => ['onMessageFinished', -4096],
            self::MESSENGER_WORKER_STOPPED          => ['onWorkerStopped', -4096],
            self::CONSOLE_COMMAND                   => ['onConsoleCommand', PHP_INT_MAX],
            self::CONSOLE_ERROR                     => ['onConsoleError', PHP_INT_MAX],
            self::CONSOLE_TERMINATE                 => [['onConsoleTerminateBookkeeping', PHP_INT_MAX], ['onConsoleTerminate', -4096]],
        ];
    }

    /**
     * Start of a main request (sub-requests share their main request's unit of work).
     */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $this->dropFailingFrames();
        // @igor-ignore - Counter of the main requests in flight, decremented at the main kernel.finish_request.
        ++$this->requestsInFlight;
        // A main request handled inside a command or a message belongs to that unit: it does not end it (audit NO-v N6).
        if (!$this->insideCommandOrMessage()) {
            $this->clearState();
        }
    }

    /**
     * End of a main request's handling (also after an exception): one main request less in flight (audit NO-v N5).
     */
    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // @igor-ignore - Counter of the main requests in flight, incremented at the main kernel.request.
            $this->requestsInFlight = max(0, $this->requestsInFlight - 1);
        }
    }

    /**
     * kernel.terminate (first listener): stale frames first, then, at a top-level terminate (no command, no message),
     * no main request is in flight any more, whatever was lost before (audit NO-v N5).
     */
    public function onTerminateBookkeeping(): void
    {
        $this->dropFailingFrames();
        if (!$this->insideCommandOrMessage()) {
            // @igor-ignore - Counter of the main requests in flight, healed at a top-level terminate.
            $this->requestsInFlight = 0;
        }
    }

    /**
     * End of the main request (after the response was sent): clears unless a command or a message is still running
     * around it (audit NO-v N6).
     */
    public function onTerminate(): void
    {
        if (!$this->insideCommandOrMessage()) {
            $this->clearState();
        }
    }

    /**
     * A console command or a Messenger message is running (the current unit of work is theirs).
     */
    public function insideCommandOrMessage(): bool
    {
        return $this->frames !== [] || $this->messageInFlight;
    }

    /**
     * Start of a Messenger message: nothing of the previous message or the worker's idle time is kept.
     */
    public function onMessageReceived(): void
    {
        $this->dropFailingFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = true;
        $this->clearState();
    }

    /**
     * End of a Messenger message (handled, failed or skipped).
     */
    public function onMessageFinished(): void
    {
        $this->dropFailingFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
        $this->clearState();
    }

    /**
     * The worker stopped: no message is in flight any more.
     */
    public function onWorkerStopped(): void
    {
        // Stale frames first: dropping one restores the flag it saved, which this handler then overrides (audit NM r3).
        $this->dropFailingFrames();
        // @igor-ignore - Unit-of-work flag, set and unset at the Messenger boundaries.
        $this->messageInFlight = false;
    }

    /**
     * Start of a command (first listener): pushes its frame and clears when it is the outermost unit. An event
     * without a command is not counted.
     */
    public function onConsoleCommand(?object $event = null): void
    {
        $command = $this->call($event, 'getCommand');
        if ($command === null) {
            return;
        }
        $this->dropFailingFrames();

        $outermost = $this->isIdle();
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate.
        $this->frames[] = [
            'command'   => $command,
            'input'     => $this->call($event, 'getInput'),
            'outermost' => $outermost,
            'failing'   => false,
            'message'   => $this->messageInFlight,
            'requests'  => $this->requestsInFlight,
        ];
        if ($outermost) {
            $this->clearState();
        }
    }

    /**
     * A command failed (first listener): its frame is marked. Symfony skips its terminate when a later error listener
     * throws; the still-marked frame is then dropped at the next main request, message, command or worker stop. There
     * is no "unmark" listener: the frame's own terminate removes it. Without a command (unknown command name) the unit
     * ends only when nothing runs.
     */
    public function onConsoleError(?object $event = null): void
    {
        $index = $this->indexOf($event);
        if ($index === null) {
            if ($this->call($event, 'getCommand') === null && $this->isIdle()) {
                $this->clearState();
            }

            return;
        }
        // @igor-ignore - Failure marker of a running command, removed with its frame.
        $this->frames[$index]['failing'] = true;
    }

    /**
     * End of a command (first listener): removes its frame and every frame above it (nested runs whose terminate
     * never came) and restores the state saved when it started. An unknown command changes nothing.
     */
    public function onConsoleTerminateBookkeeping(?object $event = null): void
    {
        $index = $this->indexOf($event);
        if ($event === null || $index === null) {
            return;
        }
        if ($this->frames[$index]['outermost']) {
            // @igor-ignore - Consumed by the late terminate listener of the same event.
            $this->pendingClear[$event] = true;
        }
        $this->removeFrom($index);
    }

    /**
     * End of a command (late listener): clears when that command was the outermost unit.
     */
    public function onConsoleTerminate(?object $event = null): void
    {
        if ($event !== null && isset($this->pendingClear[$event])) {
            unset($this->pendingClear[$event]);
            $this->clearState();
        }
    }

    /**
     * Zeroes the tracking (kernel.reset); worker correctness never depends on it.
     */
    public function reset(): void
    {
        $this->frames           = [];
        $this->messageInFlight  = false;
        $this->requestsInFlight = 0;
        $this->pendingClear     = new WeakMap();
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

    private function isIdle(): bool
    {
        return $this->frames === [] && !$this->messageInFlight && $this->requestsInFlight === 0;
    }

    /**
     * The frame of the command and input of a console event (the most recent match).
     */
    private function indexOf(?object $event): ?int
    {
        $command = $this->call($event, 'getCommand');
        if ($command === null) {
            return null;
        }
        $input = $this->call($event, 'getInput');
        for ($i = count($this->frames) - 1; $i >= 0; --$i) {
            if ($this->frames[$i]['command'] === $command && $this->frames[$i]['input'] === $input) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Removes the frame and every frame above it, and restores the state saved by that frame.
     */
    private function removeFrom(int $index): void
    {
        $frame = $this->frames[$index];
        // @igor-ignore - Stack of the running commands (no request data), popped by their own terminate.
        $this->frames = array_slice($this->frames, 0, $index);
        // @igor-ignore - Restored to the state of the moment the command started.
        $this->messageInFlight = $frame['message'];
        // @igor-ignore - Restored to the state of the moment the command started.
        $this->requestsInFlight = $frame['requests'];
    }

    /**
     * Drops the first frame still marked failing (its terminate never came), with the frames above it.
     */
    private function dropFailingFrames(): void
    {
        foreach ($this->frames as $index => $frame) {
            if ($frame['failing']) {
                $this->removeFrom($index);

                return;
            }
        }
    }

    private function call(?object $event, string $method): ?object
    {
        if ($event === null || !method_exists($event, $method)) {
            return null;
        }
        $value = $event->{$method}();

        return is_object($value) ? $value : null;
    }
}
