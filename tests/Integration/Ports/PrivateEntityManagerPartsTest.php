<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\VaultBundle\Doctrine\PrivateEntityManagerConnection;
use Nowo\VaultBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Doctrine\VaultManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultSavepoint;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function assert;

/**
 * 1.19.0 private entity manager parts: the rollback middleware, the replacement rules, the boundary drop that cannot be
 * poisoned, the registry the vault services see, the savepoint helper and the build checks.
 */
final class PrivateEntityManagerPartsTest extends KernelTestCase
{
    use VariantKernelSupport;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    #[Test]
    public function rollbacksAndFailedCommitsMarkThePrivateManagerStale(): void
    {
        $private = $this->privateManager();
        $inner   = $this->createMock(DriverConnection::class);
        $inner->method('exec')->willReturn(0);
        $inner->expects(self::exactly(2))->method('commit')->willReturnOnConsecutiveCalls(null, self::throwException(new RuntimeException('commit failed')));
        $connection = new PrivateEntityManagerConnection($inner, $private);

        $connection->commit();
        $connection->exec('SAVEPOINT DOCTRINE_2');
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        self::assertFalse($private->isStale());
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE_2');
        self::assertTrue($private->isStale());

        $private->reset();
        $private->live();
        $connection->rollBack();
        self::assertTrue($private->isStale());

        $private->reset();
        $private->live();
        try {
            $connection->commit();
            self::fail('the commit failure is rethrown');
        } catch (RuntimeException) {
        }
        self::assertTrue($private->isStale());

        $driver = $this->createMock(Driver::class);
        $driver->method('connect')->willReturn($this->createMock(DriverConnection::class));
        self::assertInstanceOf(PrivateEntityManagerConnection::class, (new PrivateEntityManagerMiddleware($private))->wrap($driver)->connect([]));
    }

    #[Test]
    public function aStaleManagerWithPendingWorkIsKeptUntilFlushed(): void
    {
        $private = $this->privateManager();
        $live    = $private->live();
        $private->persist(VaultItem::forScope(VaultItemType::Login, 'Pending', 'org-1', null, 'c'));
        $private->connectionRolledBack();

        self::assertSame($live, $private->live(), 'pending inserts survive a rollback signal');
        $private->flush();
        $private->connectionRolledBack();
        self::assertNotSame($live, $private->live(), 'no pending work: replaced');
        $private->clear();
        (new VaultEntityManager($this->doctrine()))->clear();
    }

    #[Test]
    public function aBoundaryThatCannotInspectOrClearStillDropsTheManager(): void
    {
        $logger  = new RecordingLogger();
        $private = new VaultEntityManager($this->doctrine(), 'default', $logger);
        $failing = $this->createMock(EntityManagerInterface::class);
        $failing->method('isOpen')->willReturn(true);
        $failing->method('getUnitOfWork')->willThrowException(new RuntimeException('change sets'));
        $failing->method('clear')->willThrowException(new RuntimeException('onClear listener'));
        (new ReflectionProperty(VaultEntityManager::class, 'current'))->setValue($private, $failing);

        $private->reset();

        self::assertFalse($private->isCreated());
        self::assertCount(2, $logger->records, 'inspection and clear failures are logged, never rethrown');

        $quiet = new VaultEntityManager($this->doctrine(), 'default', new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger down');
            }
        });
        (new ReflectionProperty(VaultEntityManager::class, 'current'))->setValue($quiet, $failing);
        $quiet->reset();
        self::assertFalse($quiet->isCreated());

        $closed = new VaultEntityManager($this->doctrine());
        $closed->live()->close();
        $closed->reset();
        self::assertFalse($closed->isCreated());
    }

    #[Test]
    public function theSourceMustBeTheConfiguredOrmManagerThatMapsTheVault(): void
    {
        $doctrine = $this->createMock(ManagerRegistry::class);
        $doctrine->method('getManager')->willReturn($this->createMock(\Doctrine\Persistence\ObjectManager::class));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not map');
        (new VaultEntityManager($doctrine, 'vault'))->source();
    }

    #[Test]
    public function undefinedMagicPropertiesFail(): void
    {
        $this->expectException(LogicException::class);
        $this->privateManager()->__get('other');
    }

    #[Test]
    public function theRegistryServesTheVaultManagerAndDelegatesTheRest(): void
    {
        $doctrine = $this->doctrine();
        $private  = new VaultEntityManager($doctrine);
        $registry = new VaultManagerRegistry($doctrine, $private, 'default');

        self::assertSame($private, $registry->getManager());
        self::assertSame($private, $registry->getManager('default'));
        self::assertSame($doctrine->getManagers(), $registry->getManagers());
        self::assertSame($doctrine->getManagerNames(), $registry->getManagerNames());
        self::assertSame($doctrine->getDefaultManagerName(), $registry->getDefaultManagerName());
        self::assertSame($doctrine->getDefaultConnectionName(), $registry->getDefaultConnectionName());
        self::assertSame($doctrine->getConnection(), $registry->getConnection());
        self::assertSame($doctrine->getConnections(), $registry->getConnections());
        self::assertSame($doctrine->getConnectionNames(), $registry->getConnectionNames());
        self::assertSame($doctrine->getManagerForClass(User::class), $registry->getManagerForClass(User::class));
        self::assertSame($private, $registry->getManagerForClass('Proxies\\__CG__\\Nowo\\VaultBundle\\Entity\\VaultItem'));
        self::assertSame($private->getRepository(VaultItem::class), $registry->getRepository(VaultItem::class));
        self::assertSame($doctrine->getRepository(User::class), $registry->getRepository(User::class));

        $other = new VaultManagerRegistry($doctrine, $private, 'vault');
        self::assertSame($doctrine->getManager(), $other->getManager(), 'another name: the application manager');
        self::assertSame($doctrine->getManager('default'), $other->resetManager('default'));
    }

    #[Test]
    public function aVaultWriteInsideATransactionRunsInASavepoint(): void
    {
        $connection = $this->privateManager()->getConnection();
        self::assertSame(1, VaultSavepoint::write($connection, static fn (): int => 1), 'outside a transaction: as is');

        $connection->beginTransaction();
        try {
            VaultSavepoint::write($connection, static function (): never {
                throw new RuntimeException('failing statement');
            });
        } catch (RuntimeException) {
        }
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is untouched');
        self::assertSame(2, VaultSavepoint::write($connection, static fn (): int => 2));
        $connection->rollBack();
    }

    #[Test]
    public function theBuildRefusesAnUnknownSourceManagerAndWrapsOnlyItsConnection(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(VaultEntityManager::class, new Definition(VaultEntityManager::class));
        $container->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))->addTag('doctrine.middleware'));
        $container->setParameter('nowo_vault.entity_manager', 'vault');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'vault' => 'doctrine.orm.vault_entity_manager']);
        $container->setDefinition('doctrine.orm.vault_entity_manager', new Definition(EntityManagerInterface::class, [new Reference('doctrine.dbal.secrets_connection')]));
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'secrets']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        $container->removeDefinition('doctrine.orm.vault_entity_manager');
        $container->setParameter('doctrine.default_connection', 'main');
        (new PrivateEntityManagerPass())->process($container);
        self::assertSame([['connection' => 'main']], $container->getDefinition(PrivateEntityManagerMiddleware::class)->getTag('doctrine.middleware'));

        (new PrivateEntityManagerPass())->process(new ContainerBuilder());
        $container->setParameter('nowo_vault.entity_manager', 'missing');
        $this->expectException(InvalidConfigurationException::class);
        (new PrivateEntityManagerPass())->process($container);
    }

    private function doctrine(): ManagerRegistry
    {
        self::bootKernel(['environment' => 'private_relation_new']);
        $container = self::getContainer();
        self::resetSchema($container);
        $doctrine = $container->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);

        return $doctrine;
    }

    private function privateManager(): VaultEntityManager
    {
        $private = new VaultEntityManager($this->doctrine());
        $private->live();

        return $private;
    }
}
