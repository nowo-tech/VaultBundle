<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Internal;

use DateTimeImmutable;
use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Event\VaultTagChangeEvent;
use Nowo\VaultBundle\Internal\DeferredVaultEventDispatcher;
use Nowo\VaultBundle\Internal\VaultTransactionLevelInterface;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
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

    public function testInsideAnApplicationTransactionNotificationsWaitForTheCommitAndAnIdlePoint(): void
    {
        $level      = $this->level(1);
        $dispatcher = $this->dispatcher($level);

        $dispatcher->deferWhile(function () use ($dispatcher): void {
            $dispatcher->dispatch($this->itemChanged('unit'), 'item');
        });
        $dispatcher->dispatch($this->itemChanged('direct'), 'item');
        $dispatcher->dispatch(new stdClass(), 'check');
        self::assertSame(['check'], $this->seen, 'held until the commit; other events at once');
        self::assertSame(2, $dispatcher->pendingCount());

        $dispatcher->connectionCommitted();
        self::assertSame(['check'], $this->seen, 'never inside the commit call');

        $level->value = 0;
        $dispatcher->deferWhile(static fn (): null => null);
        self::assertSame(['check', 'item:unit', 'item:direct'], $this->seen, 'released at the next idle point');
        self::assertSame(0, $dispatcher->pendingCount());
    }

    public function testRollbacksAndReleasedSavepointsFollowTheLevels(): void
    {
        $level      = $this->level(2);
        $dispatcher = $this->dispatcher($level);
        $dispatcher->dispatch($this->itemChanged('level-2-released'), 'item');
        $dispatcher->savepointReleased(2);
        $level->value = 2;
        $dispatcher->dispatch($this->itemChanged('level-2-rolled-back'), 'item');
        $dispatcher->connectionRolledBack(2);
        $dispatcher->savepointReleased(1);
        self::assertSame(1, $dispatcher->pendingCount(), 'the released one is now at level 1');

        $dispatcher->connectionCommitted();
        $dispatcher->boundary();
        self::assertSame(['item:level-2-released'], $this->seen);

        $level->value = 3;
        $dispatcher->dispatch($this->itemChanged('unknown-savepoint'), 'item');
        $dispatcher->connectionRolledBack(null);
        $dispatcher->dispatch($this->itemChanged('transaction'), 'item');
        $dispatcher->connectionRolledBack();
        self::assertSame(0, $dispatcher->pendingCount());
    }

    public function testTheBoundaryDropsUncommittedNotificationsAndResetDropsEverything(): void
    {
        $logger     = new RecordingLogger();
        $level      = $this->level(1);
        $dispatcher = $this->dispatcher($level, $logger);

        $dispatcher->dispatch($this->itemChanged('open'), 'item');
        $dispatcher->boundary();
        self::assertSame([], $this->seen);
        self::assertSame(0, $dispatcher->pendingCount());

        $dispatcher->dispatch($this->itemChanged('committed'), 'item');
        $dispatcher->connectionCommitted();
        $dispatcher->dispatch($this->itemChanged('held'), 'item');
        $dispatcher->reset();
        self::assertSame([], $this->seen);
        self::assertSame(0, $dispatcher->pendingCount());
        self::assertCount(3, $logger->records, 'every drop is logged');
    }

    public function testAFailingListenerOfACommittedNotificationIsLoggedAndTheRestStillRuns(): void
    {
        $logger     = new RecordingLogger();
        $level      = $this->level(1);
        $inner      = new EventDispatcher();
        $dispatcher = new DeferredVaultEventDispatcher($inner, $level, $logger);
        $inner->addListener('item', function (VaultItemChangeEvent $event) use ($dispatcher): void {
            $this->seen[] = 'item:' . $event->getItemId();
            if ($event->getItemId() === 'first') {
                // Re-entrant: a listener's own notification is dispatched after the running release, not inside it.
                $dispatcher->dispatch($this->itemChanged('from-listener'), 'item');

                throw new RuntimeException('listener failed');
            }
        });

        $dispatcher->dispatch($this->itemChanged('first'), 'item');
        $dispatcher->dispatch($this->itemChanged('second'), 'item');
        $dispatcher->connectionCommitted();
        $level->value = 0;
        $dispatcher->boundary();

        self::assertSame(['item:first', 'item:from-listener', 'item:second'], $this->seen);
        self::assertCount(1, $logger->records);
    }

    private function dispatcher(?VaultTransactionLevelInterface $level = null, ?RecordingLogger $logger = null): DeferredVaultEventDispatcher
    {
        $inner = new EventDispatcher();
        foreach (['item', 'check', 'tag', 'folder'] as $name) {
            $inner->addListener($name, function (object $event) use ($name): void {
                $this->seen[] = $event instanceof VaultItemChangeEvent ? $name . ':' . $event->getItemId() : $name;
            });
        }

        return new DeferredVaultEventDispatcher($inner, $level, $logger);
    }

    private function level(int $value): VaultTransactionLevelInterface
    {
        return new class($value) implements VaultTransactionLevelInterface {
            public function __construct(public int $value)
            {
            }

            public function transactionLevel(): int
            {
                return $this->value;
            }
        };
    }

    private function itemChanged(string $id): VaultItemChangeEvent
    {
        return new VaultItemChangeEvent(null, $id, 'user:1', VaultItemChangeEvent::KIND_UPDATED, false, new DateTimeImmutable());
    }
}
