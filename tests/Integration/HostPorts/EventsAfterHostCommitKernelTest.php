<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\HostPorts;

use Doctrine\DBAL\Connection;
use Nowo\VaultBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Internal\DeferredVaultEventBoundaryListener;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function assert;

/**
 * 2.1.0 (DDD-host checklist B4, REQ-ARCH-001 rule 7): inside a transaction of the application, the vault's change
 * notifications wait for the application's commit (the vault's unit is only a savepoint there), are dropped when it
 * rolls back, follow released and rolled-back savepoints, and are dispatched at the next idle point, never inside the
 * commit call. doctrine/dbal 4 (the connection hook); with DBAL 3 the 1.20 behaviour stays.
 */
#[Group('database')]
final class EventsAfterHostCommitKernelTest extends KernelTestCase
{
    use HostPortsSupport;

    private const PAYLOAD = ['password' => 'REDACTED-TEST'];

    /** @var list<string> */
    private array $seen = [];

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    protected function setUp(): void
    {
        $this->bootVariant('private_relation_new');
        $dispatcher = $this->container->get('event_dispatcher');
        assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(VaultEvents::ITEM_CHANGED, function (VaultItemChangeEvent $event): void {
            $this->seen[] = 'item:' . $event->getKind() . ':' . $this->connection()->getTransactionNestingLevel();
        });
        $dispatcher->addListener(VaultEvents::FOLDER_CHANGED, function (VaultFolderChangeEvent $event): void {
            $this->seen[] = 'folder:' . $event->getKind();
        });
    }

    #[Test]
    public function notificationsWaitForTheApplicationsCommitAndRunOutsideIt(): void
    {
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $this->assertTheDbal3Behaviour();

            return;
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'In host transaction', self::PAYLOAD);
        // A write service called outside a vault unit is held the same way.
        $this->folderService()->create('Folder', $this->users['alice']);
        self::assertSame([], $this->seen, 'nothing before the commit');
        $connection->commit();
        self::assertSame([], $this->seen, 'never inside the commit call');

        // The next vault notification outside a transaction releases the committed ones first, in order.
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'After', self::PAYLOAD);
        self::assertSame(['item:created:0', 'folder:created', 'item:created:0'], $this->seen);
    }

    #[Test]
    public function aRollbackDropsThemAndTheBoundaryReleasesCommittedOnes(): void
    {
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $this->assertTheDbal3Behaviour();

            return;
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'Rolled back', self::PAYLOAD);
        $connection->rollBack();
        $this->boundary();
        self::assertSame([], $this->seen, 'the rows are gone: so are their notifications');

        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'Committed', self::PAYLOAD);
        $connection->commit();
        $this->boundary();
        self::assertSame(['item:created:0'], $this->seen, 'released at the end of the request / message / command');
    }

    #[Test]
    public function savepointsOfTheApplicationAreFollowed(): void
    {
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $this->assertTheDbal3Behaviour();

            return;
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'Inner rolled back', self::PAYLOAD);
        $connection->rollBack();
        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'Inner released', self::PAYLOAD);
        $connection->commit();
        self::assertSame([], $this->seen);
        $connection->commit();
        $this->boundary();

        self::assertSame(['item:created:0'], $this->seen, 'only the notification of the released savepoint');
    }

    #[Test]
    public function aBoundaryDropsWhatNeverCommitted(): void
    {
        if (!PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $this->assertTheDbal3Behaviour();

            return;
        }

        $connection = $this->connection();
        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'Open', self::PAYLOAD);
        $this->boundary();
        $connection->commit();
        $this->boundary();

        self::assertSame([], $this->seen, 'a transaction still open at the boundary never reaches the next request');
    }

    /**
     * doctrine/dbal 3 (no connection hook): the 2.0 behaviour, the notifications leave when the vault's unit ends, even
     * inside the application's transaction (the database CI jobs run with --fail-on-skipped, so this is asserted, not
     * skipped).
     */
    private function assertTheDbal3Behaviour(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $this->items()->create($this->ref('alice'), VaultItemType::Login, 'DBAL 3', self::PAYLOAD);
        $connection->rollBack();

        self::assertSame(['item:created:1'], $this->seen);
    }

    private function boundary(): void
    {
        // What `kernel.terminate`, `console.terminate` and the Messenger worker events run.
        $listener = $this->container->get(DeferredVaultEventBoundaryListener::class);
        assert($listener instanceof DeferredVaultEventBoundaryListener);
        $listener->onBoundary();
    }

    private function connection(): Connection
    {
        return self::hostManager($this->container)->getConnection();
    }

    private function folderService(): VaultFolderService
    {
        $service = $this->container->get(VaultFolderService::class);
        assert($service instanceof VaultFolderService);

        return $service;
    }
}
