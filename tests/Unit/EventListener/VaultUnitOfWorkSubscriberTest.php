<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\EventListener;

use Nowo\VaultBundle\EventListener\VaultUnitOfWorkSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.19.0: the private entity manager is dropped at every outermost unit-of-work boundary (main request, Messenger
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
        $subscriber->onTerminate();
        self::assertSame(2, $this->resets);
    }

    #[Test]
    public function nestedCommandsResetOnlyAtTheOutermostOne(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onConsoleCommand();
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleTerminate();
        self::assertSame(1, $this->resets, 'the inner command keeps the outer unit');
        $subscriber->onConsoleTerminate();
        self::assertSame(2, $this->resets);
        $subscriber->onConsoleTerminate();
        self::assertSame(3, $this->resets, 'the depth never goes below zero');
    }

    #[Test]
    public function aCommandRunInsideAMessageKeepsTheMessageUnit(): void
    {
        $subscriber = $this->subscriber();

        $subscriber->onMessageReceived();
        self::assertSame(1, $this->resets);
        $subscriber->onConsoleCommand();
        $subscriber->onConsoleTerminate();
        self::assertSame(1, $this->resets);
        $subscriber->onMessageFinished();
        self::assertSame(2, $this->resets);

        $subscriber->onMessageReceived();
        $subscriber->onWorkerStopped();
        $subscriber->onConsoleCommand();
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
        (new VaultUnitOfWorkSubscriber())->clearState();
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
