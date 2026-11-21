<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Tests\App\Ports\PolicyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectoryV119;
use Nowo\VaultBundle\Tests\Support\Removed\LegacyAccessChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Port wiring at build time (2.0: the framework-free interfaces only): the port interface is aliased to the configured
 * service, the built-in or the application's own alias; a service or a decorator whose class does not implement it, or
 * cannot be loaded (it still implements a removed interface), fails the build; ambiguous wirings fail instead of
 * dropping a service.
 */
final class VaultPortsPassTest extends TestCase
{
    #[Test]
    public function aBuiltInIsAliasedAndAskedThroughItsGate(): void
    {
        $container = $this->container('access_checker', 'nowo_vault.access_checker.default', ConfigurableVaultAccessChecker::class, true);
        (new VaultPortsPass())->process($container);

        self::assertSame('nowo_vault.access_checker.default', (string) $container->getAlias(VaultAccessPolicyInterface::class));
        self::assertEquals([new Reference(VaultAccessPolicyInterface::class)], $container->getDefinition('nowo_vault.port.access_checker')->getArguments());
        self::assertFalse($container->hasParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.mode'), 'no mode since 2.0');
    }

    #[Test]
    public function aConfiguredServiceImplementingThePortIsAliased(): void
    {
        $container = $this->container('team_membership', 'app.ports', PolicyHostPorts::class);
        (new VaultPortsPass())->process($container);

        self::assertSame('app.ports', (string) $container->getAlias(VaultTeamMembershipProviderInterface::class));
    }

    #[Test]
    public function aServiceNotImplementingThePortFailsTheBuild(): void
    {
        $container = $this->container('access_checker', 'app.ports', stdClass::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement "' . VaultAccessPolicyInterface::class . '"');
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function aServiceThatStillImplementsTheRemovedInterfaceFailsTheBuild(): void
    {
        $container = $this->container('access_checker', 'app.ports', LegacyAccessChecker::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be loaded. A class that still implements the removed "Nowo\VaultBundle\Security\VaultAccessCheckerInterface" cannot load: implement "' . VaultAccessPolicyInterface::class . '"');
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function aServiceOfUnknownClassOrAMissingServiceIsLeftToTheContainer(): void
    {
        $factory = $this->container('access_checker', 'app.ports', stdClass::class);
        $factory->getDefinition('app.ports')->setFactory([PolicyHostPorts::class, 'create']);
        (new VaultPortsPass())->process($factory);
        self::assertSame('app.ports', (string) $factory->getAlias(VaultAccessPolicyInterface::class));

        $missing = $this->container('access_checker', 'app.ports', PolicyHostPorts::class);
        $missing->removeDefinition('app.ports');
        (new VaultPortsPass())->process($missing);
        self::assertSame('app.ports', (string) $missing->getAlias(VaultAccessPolicyInterface::class));
    }

    #[Test]
    public function aDecoratorImplementingThePortIsKept(): void
    {
        $container = $this->container('access_checker', 'nowo_vault.access_checker.default', ConfigurableVaultAccessChecker::class, true);
        $container->setAlias('app.vault_checker', 'nowo_vault.access_checker.default');
        $container->register('app.audit', PolicyHostPorts::class)->setDecoratedService('app.vault_checker');
        $container->register('app.audit2', PolicyHostPorts::class)->setDecoratedService('app.audit');
        $container->register('app.factory_audit', stdClass::class)->setFactory([PolicyHostPorts::class, 'create'])->setDecoratedService(VaultAccessPolicyInterface::class);
        (new VaultPortsPass())->process($container);

        self::assertTrue($container->hasDefinition('app.audit2'));
    }

    #[Test]
    public function aDecoratorWithoutThePortFailsTheBuild(): void
    {
        // Audits NJ-3, NJV-2: of the service itself, through an alias, of another decorator, or of the interface.
        foreach (['nowo_vault.access_checker.default', 'app.vault_checker', 'app.inner_audit', VaultAccessPolicyInterface::class] as $decorated) {
            $container = $this->container('access_checker', 'nowo_vault.access_checker.default', ConfigurableVaultAccessChecker::class, true);
            $container->setAlias('app.vault_checker', 'nowo_vault.access_checker.default');
            $container->register('app.inner_audit', PolicyHostPorts::class)->setDecoratedService('nowo_vault.access_checker.default');
            $container->register('app.audit', stdClass::class)->setDecoratedService($decorated);

            try {
                (new VaultPortsPass())->process($container);
                self::fail('a decorator of ' . $decorated . ' without the port must fail the build');
            } catch (LogicException $exception) {
                self::assertStringContainsString('"app.audit" decorates the vault port ' . VaultAccessPolicyInterface::class, $exception->getMessage());
                self::assertStringContainsString('implement it on the decorator', $exception->getMessage());
            }
        }

        $removed = $this->container('team_membership', 'app.ports', PolicyHostPorts::class);
        $removed->register('app.legacy_audit', LegacyAccessChecker::class)->setDecoratedService('app.ports');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"app.legacy_audit" decorates the vault port');
        (new VaultPortsPass())->process($removed);
    }

    #[Test]
    public function theApplicationsOwnAliasWinsOverABuiltIn(): void
    {
        $container = $this->container('team_membership', NullVaultTeamMembershipResolver::class, NullVaultTeamMembershipResolver::class, true);
        $container->register('app.teams', PolicyHostPorts::class);
        $container->setAlias(VaultTeamMembershipProviderInterface::class, 'app.teams');
        (new VaultPortsPass())->process($container);
        self::assertSame('app.teams', (string) $container->getAlias(VaultTeamMembershipProviderInterface::class));

        $locator = $this->container('user_locator', VaultUserLocator::class, VaultUserLocator::class, true);
        $locator->setDefinition(VaultUserDirectoryInterface::class, new Definition(PortsUserDirectoryV119::class));
        (new VaultPortsPass())->process($locator);
        self::assertTrue($locator->hasDefinition(VaultUserDirectoryInterface::class), 'a definition under the interface id is the application\'s own');

        $wrong = $this->container('scope_access', 'app.ports', PolicyHostPorts::class, true);
        $wrong->register('app.scopes', stdClass::class);
        $wrong->setAlias(VaultScopeAccessProviderInterface::class, 'app.scopes');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"app.scopes" (stdClass)');
        (new VaultPortsPass())->process($wrong);
    }

    #[Test]
    public function theApplicationsAliasAndAnotherConfiguredServiceFailTheBuild(): void
    {
        $container = $this->container('team_membership', 'app.ports', PolicyHostPorts::class);
        $container->register('app.teams', PolicyHostPorts::class);
        $container->setAlias(VaultTeamMembershipProviderInterface::class, 'app.teams');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('keep only one');
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function anInactivePortHasNoGate(): void
    {
        $container = $this->container('reauthentication', '', stdClass::class);
        (new VaultPortsPass())->process($container);

        self::assertFalse($container->hasDefinition('nowo_vault.port.reauthentication'));
        (new VaultPortsPass())->process(new ContainerBuilder());

        $noOption = new ContainerBuilder();
        $noOption->register('app.ports', PolicyHostPorts::class);
        $noOption->setParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.service', 'app.ports');
        $noOption->setDefinition(VaultPortsPass::GATE_PREFIX . 'access_checker', new Definition(VaultPortsPass::PORTS['access_checker']['gate']));
        (new VaultPortsPass())->process($noOption);
        self::assertSame('app.ports', (string) $noOption->getAlias(VaultAccessPolicyInterface::class));
    }

    private function container(string $port, string $service, string $class, bool $builtIn = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        if ($service !== '') {
            $container->register($service, $class);
        }
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.service', $service);
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.built_in', $builtIn);
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.option', 'nowo_vault.' . $port);
        $container->setDefinition(VaultPortsPass::GATE_PREFIX . $port, new Definition(VaultPortsPass::PORTS[$port]['gate']));

        return $container;
    }
}
