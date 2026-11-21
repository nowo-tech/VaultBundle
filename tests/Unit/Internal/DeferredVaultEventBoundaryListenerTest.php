<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Internal;

use DateTimeImmutable;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Internal\DeferredVaultEventBoundaryListener;
use Nowo\VaultBundle\Internal\DeferredVaultEventDispatcher;
use Nowo\VaultBundle\Internal\VaultTransactionLevelInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(DeferredVaultEventBoundaryListener::class)]
final class DeferredVaultEventBoundaryListenerTest extends TestCase
{
    private int $seen = 0;

    public function testTheMainRequestStartsEmptyAndItsEndReleasesWhatCommitted(): void
    {
        $dispatcher = $this->dispatcher();
        $listener   = new DeferredVaultEventBoundaryListener([$dispatcher]);
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $this->holdCommitted($dispatcher);
        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        $listener->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $dispatcher->pendingCount(), 'sub-requests change nothing');

        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(0, $dispatcher->pendingCount(), 'nothing of an earlier request reaches this one');
        self::assertSame(0, $this->seen);

        $this->holdCommitted($dispatcher);
        $listener->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(1, $this->seen);

        $this->holdCommitted($dispatcher);
        $listener->onBoundary();
        self::assertSame(2, $this->seen);
    }

    private function holdCommitted(DeferredVaultEventDispatcher $dispatcher): void
    {
        $dispatcher->dispatch(new VaultItemChangeEvent(null, 'i', 'user:1', VaultItemChangeEvent::KIND_UPDATED, false, new DateTimeImmutable()), 'item');
        $dispatcher->connectionCommitted();
    }

    private function dispatcher(): DeferredVaultEventDispatcher
    {
        $inner = new EventDispatcher();
        $inner->addListener('item', function (): void {
            ++$this->seen;
        });

        return new DeferredVaultEventDispatcher($inner, new class implements VaultTransactionLevelInterface {
            public function transactionLevel(): int
            {
                return 1;
            }
        });
    }
}
