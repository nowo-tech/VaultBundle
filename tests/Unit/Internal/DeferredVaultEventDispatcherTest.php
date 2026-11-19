<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Internal;

use DateTimeImmutable;
use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Event\VaultTagChangeEvent;
use Nowo\VaultBundle\Internal\DeferredVaultEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(DeferredVaultEventDispatcher::class)]
final class DeferredVaultEventDispatcherTest extends TestCase
{
    /** @var list<string> */
    private array $seen = [];

    public function testOutsideAUnitEverythingIsDispatchedAtOnce(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->dispatch($this->itemChanged('a'), 'item');
        $dispatcher->dispatch(new stdClass(), 'check');

        self::assertSame(['item:a', 'check'], $this->seen);
    }

    public function testChangeNotificationsWaitForTheEndOfTheUnitOtherEventsDoNot(): void
    {
        $dispatcher = $this->dispatcher();

        $result = $dispatcher->deferWhile(function () use ($dispatcher): string {
            $dispatcher->dispatch($this->itemChanged('a'), 'item');
            $dispatcher->dispatch(new stdClass(), 'check');
            $dispatcher->dispatch(new VaultTagChangeEvent(null, 't', 'user:1', VaultTagChangeEvent::KIND_CREATED, new DateTimeImmutable()), 'tag');
            self::assertSame(['check'], $this->seen, 'access checks answer the caller at once');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['check', 'item:a', 'tag'], $this->seen);
    }

    public function testNestedUnitsDispatchOnlyWhenTheOutermostEndsAndAFailedInnerUnitDropsOnlyItsOwn(): void
    {
        $dispatcher = $this->dispatcher();

        $dispatcher->deferWhile(function () use ($dispatcher): void {
            $dispatcher->dispatch($this->itemChanged('outer'), 'item');
            try {
                $dispatcher->deferWhile(function () use ($dispatcher): never {
                    $dispatcher->dispatch($this->itemChanged('inner-failed'), 'item');

                    throw new RuntimeException('rolled back');
                });
            } catch (RuntimeException) {
            }
            $dispatcher->deferWhile(static function () use ($dispatcher): void {
                $dispatcher->dispatch(new VaultFolderChangeEvent(null, 'f', 'user:1', VaultFolderChangeEvent::KIND_TRASHED, new DateTimeImmutable()), 'folder');
            });
            self::assertSame([], $this->seen);
        });

        self::assertSame(['item:outer', 'folder'], $this->seen);
    }

    public function testAFailedUnitDropsItsNotificationsAndRethrows(): void
    {
        $dispatcher = $this->dispatcher();

        try {
            $dispatcher->deferWhile(function () use ($dispatcher): never {
                $dispatcher->dispatch($this->itemChanged('a'), 'item');

                throw new RuntimeException('rolled back');
            });
            self::fail('rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame('rolled back', $exception->getMessage());
        }

        $dispatcher->dispatch($this->itemChanged('b'), 'item');
        self::assertSame(['item:b'], $this->seen, 'the depth is back to zero');
    }

    public function testResetEmptiesTheQueue(): void
    {
        $dispatcher = $this->dispatcher();
        $dispatcher->deferWhile(function () use ($dispatcher): void {
            $dispatcher->dispatch($this->itemChanged('a'), 'item');
            $dispatcher->reset();
        });

        self::assertSame([], $this->seen);
        $dispatcher->dispatch($this->itemChanged('b'), 'item');
        self::assertSame(['item:b'], $this->seen);
    }

    private function dispatcher(): DeferredVaultEventDispatcher
    {
        $inner = new EventDispatcher();
        foreach (['item', 'check', 'tag', 'folder'] as $name) {
            $inner->addListener($name, function (object $event) use ($name): void {
                $this->seen[] = $event instanceof VaultItemChangeEvent ? $name . ':' . $event->getItemId() : $name;
            });
        }

        return new DeferredVaultEventDispatcher($inner);
    }

    private function itemChanged(string $id): VaultItemChangeEvent
    {
        return new VaultItemChangeEvent(null, $id, 'user:1', VaultItemChangeEvent::KIND_UPDATED, false, new DateTimeImmutable());
    }
}
