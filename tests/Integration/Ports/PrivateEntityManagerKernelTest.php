<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Doctrine\VaultManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultSavepoint;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\EventListener\VaultUnitOfWorkSubscriber;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultItemUpdater;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function assert;

/**
 * 1.19.0 private entity manager in a real kernel (REQ-ARCH-001 rule 6, REQ-RUNTIME-002 rule 4): a vault write never
 * flushes, clears, closes or resets the application's manager; a failed vault write closes only the private manager,
 * which comes back on the next call; a host transaction includes the vault rows (rolled back with it, the private
 * identity map is replaced) and a failing vault statement inside it rolls back to its own savepoint; a unit-of-work
 * boundary drops pending vault changes and logs them. The 1.18 wiring is shown flushing the host changes, for contrast.
 */
final class PrivateEntityManagerKernelTest extends KernelTestCase
{
    use VariantKernelSupport;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    #[Test]
    public function aVaultWriteNeverFlushesTheApplicationsPendingChanges(): void
    {
        [$container, $users] = $this->boot('private_relation_legacy');
        $host                = self::hostManager($container);
        $alice               = $users['carol'];
        assert($alice instanceof User);
        $alice->setEmail('changed-but-not-flushed@example.com');

        $ids = self::seedVault($container, $users);
        $this->eraser($container)->erase((string) UserIdResolver::getId($users['bob']));

        self::assertTrue($host->isOpen());
        self::assertTrue($host->contains($alice));
        $host->getUnitOfWork()->computeChangeSets();
        self::assertTrue($host->getUnitOfWork()->isScheduledForUpdate($alice), 'the application change is still pending');
        self::assertSame('carol@example.com', $host->getConnection()->fetchOne('SELECT email FROM users WHERE id = ?', [$alice->getId()]));
        self::assertFalse($host->contains($this->item($container, $ids['personal'])), 'vault rows never enter the application unit of work');
        self::assertSame(3, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items'));
    }

    #[Test]
    public function the118WiringFlushesThem(): void
    {
        [$container, $users] = $this->boot('legacy_relation_legacy');
        $alice               = $users['carol'];
        assert($alice instanceof User);
        $alice->setEmail('flushed-by-the-vault@example.com');

        self::seedVault($container, $users);

        self::assertSame('flushed-by-the-vault@example.com', self::hostManager($container)->getConnection()->fetchOne('SELECT email FROM users WHERE id = ?', [$alice->getId()]), '1.18 behaviour, kept by private_entity_manager: false');
    }

    #[Test]
    public function aFailedVaultWriteClosesOnlyThePrivateManager(): void
    {
        [$container, $users] = $this->boot('private_relation_legacy');
        $host                = self::hostManager($container);
        $vault               = self::vaultManager($container);
        assert($vault instanceof VaultEntityManager);
        $tags = $container->get(VaultTagService::class);
        assert($tags instanceof VaultTagService);
        $tags->findOrCreate($users['alice'], 'work');
        $first = $vault->live();

        try {
            $vault->persist(new VaultTag('work', $users['alice']));
            $vault->flush();
            self::fail('the duplicate tag is refused');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertFalse($first->isOpen());
        self::assertTrue($host->isOpen(), 'the application manager stays open');
        self::assertNotSame($first, $vault->live(), 'a fresh private manager on the next call');
        $creator = $container->get(VaultItemCreator::class);
        assert($creator instanceof VaultItemCreator);
        $item = $creator->create(VaultItemType::Login, 'After the failure', $users['alice'], ['password' => 'REDACTED-TEST']);
        self::assertSame('After the failure', $this->item($container, $item->getId())?->getTitle());

        $registry = $container->get(VaultManagerRegistry::class);
        assert($registry instanceof VaultManagerRegistry);
        self::assertSame($vault, $registry->getManager('default'));
        self::assertSame($vault, $registry->getManagerForClass(VaultItem::class));
        self::assertSame($host, $registry->getManagerForClass(User::class));
        self::assertSame($vault, $registry->resetManager('default'));
        self::assertFalse($vault->isCreated());
        self::assertSame($host, self::hostManager($container), 'resetting the vault manager never resets the application one');
    }

    #[Test]
    public function aHostTransactionIncludesTheVaultRowsAndARollbackReplacesTheIdentityMap(): void
    {
        [$container, $users] = $this->boot('private_relation_legacy');
        $vault               = self::vaultManager($container);
        assert($vault instanceof VaultEntityManager);
        $connection = self::hostManager($container)->getConnection();
        $creator    = $container->get(VaultItemCreator::class);
        assert($creator instanceof VaultItemCreator);

        $connection->beginTransaction();
        $item = $creator->create(VaultItemType::Login, 'Rolled back', $users['alice'], ['password' => 'REDACTED-TEST']);
        $connection->rollBack();

        self::assertTrue($vault->isStale(), 'the rollback middleware saw the rollback');
        self::assertNull($this->item($container, $item->getId()), 'gone with the host transaction; not served from a stale identity map');

        $connection->beginTransaction();
        $connection->insert('users', ['email' => 'host-row@example.com', 'password' => 'x']);
        try {
            VaultSavepoint::write($connection, static function (): never {
                throw new RuntimeException('a failing vault statement');
            });
        } catch (RuntimeException) {
        }
        $kept = $creator->create(VaultItemType::Login, 'Inside the host transaction', $users['alice'], ['password' => 'REDACTED-TEST']);
        $connection->commit();

        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM users WHERE email = 'host-row@example.com'"), 'the host row survives the failed vault statement');
        self::assertSame('Inside the host transaction', $this->item($container, $kept->getId())?->getTitle());
    }

    #[Test]
    public function aBoundaryDropsPendingVaultChangesAndLogsThem(): void
    {
        [$container, $users] = $this->boot('private_relation_legacy');
        $ids                 = self::seedVault($container, $users);
        $doctrine            = $container->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $logger  = new RecordingLogger();
        $private = new VaultEntityManager($doctrine, 'default', $logger);
        $item    = $private->find(VaultItem::class, $ids['personal']);
        assert($item instanceof VaultItem);
        $item->setTitle('Edited, never saved');

        (new VaultUnitOfWorkSubscriber([$private]))->onTerminate();

        self::assertFalse($private->isCreated());
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame([VaultItem::class => 1], $logger->records[0]['context']['changes']);
        self::assertSame('Title-P', $this->item($container, $ids['personal'])?->getTitle());

        // The bundle's own subscriber drops the bundle's manager at every boundary.
        $vault = self::vaultManager($container);
        assert($vault instanceof VaultEntityManager);
        $vault->live();
        $subscriber = $container->get(VaultUnitOfWorkSubscriber::class);
        assert($subscriber instanceof VaultUnitOfWorkSubscriber);
        $subscriber->onTerminate();
        self::assertFalse($vault->isCreated());
    }

    #[Test]
    public function updatesThroughTheServicesAreSavedByThePrivateManager(): void
    {
        [$container, $users] = $this->boot('private_relation_legacy');
        $ids                 = self::seedVault($container, $users);
        $updater             = $container->get(VaultItemUpdater::class);
        assert($updater instanceof VaultItemUpdater);
        $item = $this->item($container, $ids['personal']);
        assert($item instanceof VaultItem);

        $updater->update($item, 'Renamed', ['password' => 'REDACTED-TEST-2'], null, $users['alice']);
        self::vaultManager($container)->clear();

        self::assertSame('Renamed', self::hostManager($container)->getConnection()->fetchOne('SELECT title FROM vault_items WHERE id = ?', [$ids['personal']]));
    }

    /**
     * @return array{ContainerInterface, array<string, UserInterface>}
     */
    private function boot(string $variant): array
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => $variant, 'debug' => false]);
        $container = self::getContainer();
        self::resetSchema($container);

        return [$container, self::seedUsers($container, false)];
    }

    private function item(ContainerInterface $container, string $id): ?VaultItem
    {
        $repository = $container->get(VaultItemRepositoryInterface::class);
        assert($repository instanceof VaultItemRepositoryInterface);

        return $repository->findById($id);
    }

    private function eraser(ContainerInterface $container): VaultPersonalDataEraser
    {
        $eraser = $container->get('test.personal_data_eraser');
        assert($eraser instanceof VaultPersonalDataEraser);

        return $eraser;
    }
}
