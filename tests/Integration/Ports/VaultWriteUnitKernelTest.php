<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
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
        yield 'private entity manager' => ['private_relation_legacy'];
        yield '1.18 mode (application manager)' => ['legacy_relation_legacy'];
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

    #[Test]
    public function the118ModeRefusesToStartOverPendingApplicationChanges(): void
    {
        [$container, $users] = $this->boot('legacy_relation_legacy');
        $carol               = $users['carol'];
        assert($carol instanceof User);
        $carol->setEmail('pending@example.com');

        try {
            $this->unit($container)->run(static fn (): null => null);
            self::fail('pending host changes are refused');
        } catch (LogicException $exception) {
            self::assertSame(DoctrineTransactionRunner::HOST_PENDING_MESSAGE, $exception->getMessage());
        }

        self::assertSame('carol@example.com', self::hostManager($container)->getConnection()->fetchOne('SELECT email FROM users WHERE id = ?', [$carol->getId()]), 'nothing was flushed');
    }

    #[Test]
    public function the118ModeReplacesAClosedApplicationManager(): void
    {
        [$container] = $this->boot('legacy_relation_legacy');
        $doctrine    = $container->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $closed = self::hostManager($container);
        $closed->close();

        self::assertSame('ok', $this->unit($container)->run(static fn (): string => 'ok'));
        $current = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $current);
        self::assertTrue($current->isOpen(), 'the closed manager was replaced (DoctrineBundle may reset its lazy service in place)');
    }

    #[Test]
    public function aUnitBuiltWithoutRegistryNorDispatcherRunsOnTheGivenManager(): void
    {
        [$container, $users] = $this->boot('legacy_relation_legacy');
        $host                = self::hostManager($container);
        $unit                = new VaultWriteUnit($host, false);

        $item = $unit->run(fn (): VaultItem => $this->creator($container)->create(VaultItemType::Login, 'Bare', $users['alice'], ['password' => 'REDACTED-TEST']));
        $unit->run(static fn () => $unit->lockForUpdate($item));

        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'));
    }

    #[Test]
    public function aRegistryWithoutAnOrmManagerFallsBackToTheGivenManager(): void
    {
        [$container, $users] = $this->boot('legacy_relation_legacy');
        $host                = self::hostManager($container);
        $registry            = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $unit = new VaultWriteUnit($host, false, $registry, 'default');

        self::assertSame('ok', $unit->run(static fn (): string => 'ok'));
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
