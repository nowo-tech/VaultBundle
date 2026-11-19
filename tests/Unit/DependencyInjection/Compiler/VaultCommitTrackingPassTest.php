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

        (new VaultCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
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
