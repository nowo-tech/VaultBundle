<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function assert;

/**
 * 1.20 (REQ-RUNTIME-003 rules 1-3): the vault write unit in a real kernel, with the private entity manager and in the
 * 1.18 mode (the application's manager): one transaction for the item and its tags, change notifications only after
 * the commit, nothing left behind by a failed unit, and the host manager's pending changes refused in the 1.18 mode.
 */
final class VaultWriteUnitKernelTest extends KernelTestCase
{
    use VariantKernelSupport;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function modes(): iterable
    {
        // Since 2.0 the only mode (the 1.18 mode on the application manager was removed).
        yield 'private entity manager' => ['private_relation_new'];
    }

    #[Test]
    #[DataProvider('modes')]
    public function aUnitCommitsTheItemAndItsTagsAndNotifiesAfterTheCommit(string $variant): void
    {
        [$container, $users] = $this->boot($variant);
        $alice               = $users['alice'];
        $seen                = $this->recordItemChanges($container);
        $countInside         = null;

        $item = $this->unit($container)->run(function () use ($container, $alice, $seen, &$countInside): VaultItem {
            $item = $this->creator($container)->create(VaultItemType::Login, 'Unit', $alice, ['password' => 'REDACTED-TEST']);
            $this->tags($container)->syncItemTags($item, $alice, ['work']);
            $countInside = $seen->count;

            return $item;
        });

        self::assertSame(0, $countInside, 'no notification while the unit runs');
        self::assertSame(1, $seen->count, 'ITEM_CHANGED once the unit committed');
        $stored = $this->item($container, $item->getId());
        self::assertInstanceOf(VaultItem::class, $stored);
        self::assertSame(['work'], $stored->getTagNames());

        // The row lock re-reads the committed row inside a unit.
        $this->unit($container)->run(function () use ($container, $stored): void {
            $this->unit($container)->lockForUpdate($stored);
        });
        self::assertSame('Unit', $stored->getTitle());
    }

    #[Test]
    #[DataProvider('modes')]
    public function aFailedUnitLeavesNothingAndNotifiesNothing(string $variant): void
    {
        [$container, $users] = $this->boot($variant);
        $seen                = $this->recordItemChanges($container);

        try {
            $this->unit($container)->run(function () use ($container, $users): never {
                $this->creator($container)->create(VaultItemType::Login, 'Rolled back', $users['alice'], ['password' => 'REDACTED-TEST']);

                throw new RuntimeException('refused');
            });
            self::fail('the failure is rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame('refused', $exception->getMessage());
        }

        self::assertSame(0, $seen->count);
        self::assertSame(0, (int) self::hostManager($container)->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'));
        self::assertTrue(self::hostManager($container)->isOpen(), 'the application manager stays open');

        $this->creator($container)->create(VaultItemType::Login, 'After', $users['alice'], ['password' => 'REDACTED-TEST']);
        self::assertSame(1, (int) self::hostManager($container)->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'), 'the next write works');
    }

    /**
     * transaction-doctrine 1.7.1 audit UI2-1 (the check of a write unit scoped to its managers): with the private
     * entity manager the unit writes only through the vault's manager, so a failed unit (a domain refusal or a storage
     * error) never clears, closes or resets the application's manager: its entities stay managed, nothing is pending.
     */
    #[Test]
    public function thePrivateUnitWritesOnlyThroughTheVaultManagerAndLeavesTheApplicationManagerAlone(): void
    {
        [$container, $users] = $this->boot('private_relation_new');
        $host                = self::hostManager($container);
        $vault               = self::vaultManager($container);
        self::assertNotSame($host, $vault);
        self::assertTrue($host->contains($users['alice']));

        $failures = [
            static fn (): never => throw new RuntimeException('refused'),
            static fn (): int => $vault->getConnection()->executeStatement('UPDATE vault_missing_table SET id = 1'),
        ];
        foreach ($failures as $failure) {
            try {
                $this->unit($container)->run(function () use ($container, $users, $failure): void {
                    $this->creator($container)->create(VaultItemType::Login, 'Scoped', $users['alice'], ['password' => 'REDACTED-TEST']);
                    $failure();
                });
                self::fail('the failure is rethrown');
            } catch (Throwable) {
                // Expected: the unit rethrows its failure.
            }

            self::assertTrue($host->isOpen(), 'the application manager is never closed');
            self::assertTrue($host->contains($users['alice']), 'the application manager is never cleared');
            $host->getUnitOfWork()->computeChangeSets();
            self::assertSame([], $host->getUnitOfWork()->getScheduledEntityInsertions(), 'the unit wrote nothing through it');
            self::assertSame([], $host->getUnitOfWork()->getScheduledEntityUpdates());
        }

        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'));
    }

    /**
     * REQ-ARCH-001 rule 6 (the only mode since 2.0): a unit runs while the application holds unflushed changes, never
     * flushes them, and leaves them pending.
     */
    #[Test]
    public function aUnitLeavesPendingApplicationChangesPending(): void
    {
        [$container, $users] = $this->boot('private_relation_new');
        $carol               = $users['carol'];
        assert($carol instanceof User);
        $carol->setEmail('pending@example.com');

        $item = $this->unit($container)->run(fn (): VaultItem => $this->creator($container)->create(VaultItemType::Login, 'Next to pending', $users['alice'], ['password' => 'REDACTED-TEST']));

        $host = self::hostManager($container);
        self::assertSame('carol@example.com', $host->getConnection()->fetchOne('SELECT email FROM users WHERE id = ?', [$carol->getId()]), 'nothing of the application was flushed');
        $host->getUnitOfWork()->computeChangeSets();
        self::assertTrue($host->getUnitOfWork()->isScheduledForUpdate($carol), 'the application change is still pending');
        self::assertSame('Next to pending', $this->item($container, $item->getId())?->getTitle());
    }

    #[Test]
    public function aUnitBuiltWithoutDispatcherRunsOnTheGivenManager(): void
    {
        [$container, $users] = $this->boot('private_relation_new');
        $unit                = new VaultWriteUnit(self::vaultManager($container));

        $item = $unit->run(fn (): VaultItem => $this->creator($container)->create(VaultItemType::Login, 'Bare', $users['alice'], ['password' => 'REDACTED-TEST']));
        $unit->run(static fn () => $unit->lockForUpdate($item));

        self::assertSame(1, (int) self::hostManager($container)->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'));
    }

    /**
     * @return array{0: ContainerInterface, 1: array<string, UserInterface>}
     */
    private function boot(string $variant): array
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => $variant, 'debug' => false]);
        $container = self::getContainer();
        self::resetSchema($container);

        return [$container, self::seedUsers($container, false)];
    }

    private function recordItemChanges(ContainerInterface $container): object
    {
        $seen = new class {
            public int $count = 0;
        };
        $dispatcher = $container->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(VaultEvents::ITEM_CHANGED, static function (VaultItemChangeEvent $event) use ($seen): void {
            ++$seen->count;
        });

        return $seen;
    }

    private function unit(ContainerInterface $container): VaultWriteUnit
    {
        $unit = $container->get(VaultWriteUnit::class);
        assert($unit instanceof VaultWriteUnit);

        return $unit;
    }

    private function creator(ContainerInterface $container): VaultItemCreator
    {
        $creator = $container->get(VaultItemCreator::class);
        assert($creator instanceof VaultItemCreator);

        return $creator;
    }

    private function tags(ContainerInterface $container): VaultTagService
    {
        $tags = $container->get(VaultTagService::class);
        assert($tags instanceof VaultTagService);

        return $tags;
    }

    private function item(ContainerInterface $container, string $id): ?VaultItem
    {
        $repository = $container->get(VaultItemRepositoryInterface::class);
        assert($repository instanceof VaultItemRepositoryInterface);

        return $repository->findById($id);
    }
}
