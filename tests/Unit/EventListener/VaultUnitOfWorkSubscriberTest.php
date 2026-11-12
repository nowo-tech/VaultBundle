<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\EventListener;

use Nowo\VaultBundle\EventListener\VaultUnitOfWorkSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.19.0 (frames, audit NM, 1.19.1): the private entity manager is dropped at every outermost unit-of-work boundary (main request, Messenger
 * message, outermost console command outside a message), never inside a nested command or a running message.
 */
final class VaultUnitOfWorkSubscriberTest extends TestCase
{
    private int $resets = 0;

    #[Test]
    public function httpBoundariesResetOnMainRequestsAndTerminate(): void
    {
        $subscriber = $this->subscriber();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $this->resets, 'a sub-request shares its main request unit of work');
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminateBookkeeping();
        $subscriber->onTerminate();
        self::assertSame(2, $this->resets);
    }

    #[Test]
    public function aMainRequestInsideACommandOrAMessageDoesNotEndTheirUnit(): void
    {
        $subscriber = $this->subscriber();
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $command    = $this->event();

        $this->start($subscriber, $command);
        self::assertSame(1, $this->resets);
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminateBookkeeping();
        $subscriber->onTerminate();
        self::assertSame(1, $this->resets, 'audit NO-v N6: the nested main request kept the command unit');
        self::assertTrue($subscriber->insideCommandOrMessage());
        $this->end($subscriber, $command);
        self::assertSame(2, $this->resets);

        // A lost finish_request is healed by the next top-level terminate (N5).
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onTerminateBookkeeping();
        $subscriber->onTerminate();
        $before = $this->resets;
        $this->start($subscriber, $this->event());
        self::assertSame($before + 1, $this->resets, 'no main request in flight any more: outermost');
    }

    #[Test]
    public function nestedCommandsResetOnlyAtTheOutermostOne(): void
    {
        $subscriber      = $this->subscriber();
        [$outer, $inner] = [$this->event(), $this->event()];

        $this->start($subscriber, $outer);
        $this->start($subscriber, $inner);
        self::assertSame(1, $this->resets, 'outermost start only');
        $this->end($subscriber, $inner);
        self::assertSame(1, $this->resets, 'the inner command keeps the outer unit');
        $this->end($subscriber, $outer);
        self::assertSame(2, $this->resets);
        $this->end($subscriber, $outer);
        $this->end($subscriber, $this->event());
        $subscriber->onConsoleTerminate();
        $subscriber->onConsoleTerminateBookkeeping();
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleCommand(new stdClass());
        self::assertSame(2, $this->resets, 'an unknown or missing command changes nothing');
    }

    #[Test]
    public function aSelfRecursiveCommandIsOneFramePerRun(): void
    {
        $subscriber      = $this->subscriber();
        $command         = new stdClass();
        [$outer, $inner] = [$this->event($command), $this->event($command)];

        $this->start($subscriber, $outer);
        $this->start($subscriber, $inner);
        $this->end($subscriber, $inner);
        self::assertSame(1, $this->resets, 'the inner run of the same command keeps the outer unit');
        $this->end($subscriber, $outer);
        self::assertSame(2, $this->resets);
    }

    #[Test]
    public function anOuterTerminateForgetsNestedCommandsThatNeverEnded(): void
    {
        $subscriber      = $this->subscriber();
        [$outer, $inner] = [$this->event(), $this->event()];

        $this->start($subscriber, $outer);
        $this->start($subscriber, $inner);   // its terminate never comes
        $this->end($subscriber, $outer);
        self::assertSame(2, $this->resets);
        $this->start($subscriber, $this->event());
        self::assertSame(3, $this->resets, 'the next command is the outermost unit again');
    }

    #[Test]
    public function aFailingFrameWithoutTerminateIsDroppedAtTheNextBoundary(): void
    {
        $subscriber = $this->subscriber();
        $failing    = $this->event();

        $subscriber->onConsoleError($this->event(null));
        self::assertSame(1, $this->resets, 'unknown command while idle: the unit ends');
        $this->start($subscriber, $failing);
        $subscriber->onConsoleError($this->event(null));
        self::assertSame(2, $this->resets, 'unknown nested command: the running unit is kept');
        $subscriber->onConsoleError($failing);   // a later error listener throws: no terminate
        $this->start($subscriber, $this->event());
        self::assertSame(3, $this->resets, 'the marked frame was dropped: outermost again');

        $marked = $this->event();
        $subscriber->reset();
        $this->start($subscriber, $marked);
        $subscriber->onConsoleError($marked);
        $this->end($subscriber, $marked);
        self::assertSame(5, $this->resets, 'a failed command that terminates normally clears as usual');
    }

    #[Test]
    public function aFrameRestoresTheMessageAndRequestStateItSaw(): void
    {
        $subscriber = $this->subscriber();
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $consume    = $this->event();

        $this->start($subscriber, $consume);                 // messenger:consume
        $subscriber->onMessageReceived();                     // a received listener throws: never finished
        $this->end($subscriber, $consume);
        $before = $this->resets;
        $this->start($subscriber, $this->event());
        self::assertSame($before + 1, $this->resets, 'the message flag was restored with the frame');

        $subscriber->reset();
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        $before = $this->resets;
        $this->start($subscriber, $this->event());
        self::assertSame($before, $this->resets, 'the outer main request is still in flight');
    }

    #[Test]
    public function theWorkerStopWinsOverTheStateSavedByAStaleFrame(): void
    {
        $subscriber = $this->subscriber();
        $failing    = $this->event();

        $subscriber->onMessageReceived();
        $this->start($subscriber, $failing);     // inside the message: saved message = true
        $subscriber->onConsoleError($failing);   // a later error listener throws: no terminate
        $subscriber->onWorkerStopped();          // drops the frame (restores true) THEN unsets the flag
        $before = $this->resets;
        $this->start($subscriber, $this->event());
        self::assertSame($before + 1, $this->resets, 'outermost: no message in flight any more');
    }

    #[Test]
    public function aCommandRunInsideAMessageKeepsTheMessageUnit(): void
    {
        $subscriber = $this->subscriber();
        $command    = $this->event();

        $subscriber->onMessageReceived();
        self::assertSame(1, $this->resets);
        $this->start($subscriber, $command);
        $this->end($subscriber, $command);
        $subscriber->onConsoleError($this->event(null));
        self::assertSame(1, $this->resets);
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->resets);

        $subscriber->onMessageReceived();
        $subscriber->onWorkerStopped();
        $this->start($subscriber, $this->event());
        self::assertSame(4, $this->resets, 'the in-flight flag ends with the worker');
    }

    #[Test]
    public function itSubscribesEveryBoundaryByName(): void
    {
        $events = VaultUnitOfWorkSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(KernelEvents::TERMINATE, $events);
        self::assertArrayHasKey('console.command', $events);
        self::assertArrayHasKey('console.terminate', $events);
        self::assertArrayHasKey(VaultUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_RECEIVED, $events);
        self::assertArrayHasKey(VaultUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_HANDLED, $events);
        self::assertArrayHasKey(VaultUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_FAILED, $events);
        self::assertArrayHasKey(VaultUnitOfWorkSubscriber::MESSENGER_WORKER_STOPPED, $events);
        self::assertArrayHasKey(VaultUnitOfWorkSubscriber::MESSENGER_WORKER_MESSAGE_SKIPPED, $events);
        self::assertArrayHasKey('console.error', $events);
        (new VaultUnitOfWorkSubscriber())->clearState();
    }

    private function start(VaultUnitOfWorkSubscriber $subscriber, object $event): void
    {
        $subscriber->onConsoleCommand($event);
    }

    private function end(VaultUnitOfWorkSubscriber $subscriber, object $event): void
    {
        $subscriber->onConsoleTerminateBookkeeping($event);
        $subscriber->onConsoleTerminate($event);
    }

    /**
     * A console event of one run: a command (a fresh one unless given) and its own input object.
     */
    private function event(?object $command = new stdClass()): object
    {
        return new class($command, new stdClass()) {
            public function __construct(private readonly ?object $command, private readonly object $input)
            {
            }

            public function getCommand(): ?object
            {
                return $this->command;
            }

            public function getInput(): object
            {
                return $this->input;
            }
        };
    }

    private function subscriber(): VaultUnitOfWorkSubscriber
    {
        $resettable = new class($this->resets) implements ResetInterface {
            public function __construct(private int &$resets)
            {
            }

            public function reset(): void
            {
                ++$this->resets;
            }
        };

        return new VaultUnitOfWorkSubscriber([$resettable]);
    }
}
