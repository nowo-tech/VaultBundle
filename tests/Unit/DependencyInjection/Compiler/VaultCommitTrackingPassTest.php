<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\VaultBundle\DependencyInjection\Compiler\VaultCommitTrackingPass;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\EventListener\VaultConflictListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.20 (REQ-RUNTIME-003, audit UE-CF-1 class): the vault's commit-tracking middleware never wraps the host's other connections by default, and is
 * not registered at all when transaction-doctrine's commit_detection already counts commits.
 */
#[CoversClass(VaultCommitTrackingPass::class)]
final class VaultCommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = VaultExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testByDefaultOnlyTheConnectionOfTheBundleEntityManagerIsWrapped(): void
    {
        $container = $this->container('bundle', 'vault');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'), 'the bundle connection');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'), 'never the host connection');
        self::assertSame(VaultExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(VaultConflictListener::class)->getArgument(1));
    }

    public function testWithoutDoctrineEntityManagerTheDefaultManagerConnectionIsWrapped(): void
    {
        $container = $this->container('bundle', null);
        $container->setParameter('doctrine.default_entity_manager', 'vault');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testAnUnresolvableManagerFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('bundle', 'missing');
        $container->setParameter('doctrine.default_connection', 'vault');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container('bundle', null);
        $container->getParameterBag()->remove(VaultExtension::COMMIT_TRACKING_PARAMETER);
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'no parameter: bundle mode; no default manager or connection: "default"');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.vault'));
    }

    public function testAllWrapsEveryConnection(): void
    {
        $container = $this->container('all', 'vault');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('all', 'vault');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');
        $container->setDefinition(VaultCommitTrackingPass::TRANSACTION_DOCTRINE_MIDDLEWARE, new Definition(CommitTrackingMiddleware::class, [new Reference(CommitLedger::class)]));

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(VaultExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.vault'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertSame(CommitLedger::class, (string) $container->getDefinition(VaultConflictListener::class)->getArgument(1));
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('bundle', 'vault');
        $container->removeDefinition(VaultConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
        $container->setDefinition(VaultCommitTrackingPass::TRANSACTION_DOCTRINE_MIDDLEWARE, new Definition(CommitTrackingMiddleware::class, [new Reference(CommitLedger::class)]));

        (new VaultCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    /**
     * Audit UE3-CF-2: a CommitLedger service without transaction-doctrine's middleware counts nothing; the vault keeps
     * its own middleware and ledger, so CONFLICT_002 is not silenced.
     */
    public function testAStrayCommitLedgerWithoutTransactionDoctrineMiddlewareKeepsTheVaultTracking(): void
    {
        $container = $this->container('bundle', 'vault');
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(VaultExtension::COMMIT_LEDGER_SERVICE));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'));
        self::assertSame(VaultExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(VaultConflictListener::class)->getArgument(1));
    }

    /**
     * Audit UE3-CF-1: the vault's entities mapped on a manager other than database.entity_manager; the connection of
     * every manager mapping them is wrapped too, the other managers' connections are not.
     */
    public function testEveryManagerMappingTheVaultEntitiesIsWrapped(): void
    {
        $container = $this->container('bundle', 'default');
        $container->setParameter('doctrine.connections', [
            'default' => 'doctrine.dbal.default_connection',
            'vault'   => 'doctrine.dbal.vault_connection',
            'app'     => 'doctrine.dbal.app_connection',
            'extra'   => 'doctrine.dbal.extra_connection',
        ]);
        $container->setDefinition('doctrine.dbal.app_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.extra_connection.configuration', new Definition());
        $container->setParameter('doctrine.entity_managers', [
            'default'   => 'doctrine.orm.default_entity_manager',
            'vault'     => 'doctrine.orm.vault_entity_manager',
            'parent'    => 'doctrine.orm.parent_entity_manager',
            'app'       => 'doctrine.orm.app_entity_manager',
            'lookalike' => 'doctrine.orm.lookalike_entity_manager',
            'ghost'     => 'doctrine.orm.ghost_entity_manager',
            'bare'      => 'doctrine.orm.bare_entity_manager',
        ]);
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection')]));
        $container->setDefinition('doctrine.orm.parent_entity_manager', new Definition(null, [new Reference('doctrine.dbal.extra_connection')]));
        $container->setDefinition('doctrine.orm.app_entity_manager', new Definition(null, [new Reference('doctrine.dbal.app_connection')]));
        $container->setDefinition('doctrine.orm.lookalike_entity_manager', new Definition(null, [new Reference('doctrine.dbal.app_connection')]));
        $container->setDefinition('doctrine.orm.default_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Reference('a'), 'App\\Entity']));
        $container->setDefinition('doctrine.orm.vault_metadata_driver', (new Definition())
            ->addMethodCall('setDefaultDriver', [new Reference('a')])
            ->addMethodCall('addDriver', [new Reference('a'), 'Nowo\\VaultBundle\\Entity']));
        $container->setDefinition('doctrine.orm.parent_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Reference('a'), 'Nowo\\VaultBundle\\']));
        $container->setDefinition('doctrine.orm.app_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Reference('a'), 'App\\Entity']));
        $container->setDefinition('doctrine.orm.lookalike_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Reference('a'), 'Nowo\\VaultBundleExtra\\Entity']));
        $container->setDefinition('doctrine.orm.ghost_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Reference('a'), 'Nowo\\VaultBundle\\Entity']));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'database.entity_manager');
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'), 'maps Nowo\\VaultBundle\\Entity');
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.extra'), 'maps a parent namespace');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.app'), 'neither the host connection nor a look-alike namespace');
    }

    public function testANonListEntityManagersParameterIsIgnored(): void
    {
        $container = $this->container('bundle', 'vault');
        $container->setParameter('doctrine.entity_managers', 'invalid');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.vault'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testOffRegistersNothingAndThePassLeavesTheContainerAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(VaultExtension::COMMIT_TRACKING_PARAMETER, 'off');

        (new VaultCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    private function container(string $mode, ?string $entityManager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(VaultExtension::COMMIT_TRACKING_PARAMETER, $mode);
        $container->setParameter('nowo_vault.entity_manager', $entityManager);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'vault' => 'doctrine.dbal.vault_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.vault_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.vault_entity_manager', new Definition(null, [new Reference('doctrine.dbal.vault_connection')]));
        $container->setDefinition(VaultExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(VaultExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(VaultConflictListener::class, new Definition(VaultConflictListener::class, [null, new Reference(VaultExtension::COMMIT_LEDGER_SERVICE), null, null]));

        return $container;
    }

    private function compile(ContainerBuilder $container): void
    {
        (new VaultCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
