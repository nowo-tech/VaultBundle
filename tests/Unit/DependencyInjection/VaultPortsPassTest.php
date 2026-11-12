<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Tests\App\Ports\BothHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\LegacyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PassThroughLegacyDecorators;
use Nowo\VaultBundle\Tests\App\Ports\PolicyHostPorts;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectory;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectoryV119;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 1.19.0 port selection at build time: the 1.19 interface wins, a decorator of the deprecated one keeps it asked (both
 * for the access ports, the deprecated one alone otherwise), a service implementing neither fails the build, the
 * deprecated alias exists only when the class implements it, and ambiguous wirings fail instead of dropping a service.
 */
final class VaultPortsPassTest extends TestCase
{
    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        $this->deprecations = [];
        set_error_handler(function (int $type, string $message): bool {
            $this->deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    #[Test]
    public function aBuiltInIsAskedThroughThe119InterfaceAndItsDeprecatedAliasIsDeprecated(): void
    {
        $container = $this->container('access_checker', 'nowo_vault.access_checker.default', ConfigurableVaultAccessChecker::class, true);
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::POLICY, $this->mode($container, 'access_checker'));
        self::assertSame('nowo_vault.access_checker.default', (string) $container->getAlias(VaultAccessPolicyInterface::class));
        self::assertTrue($container->getAlias(VaultAccessCheckerInterface::class)->isDeprecated());
        self::assertSame([], $this->deprecations, 'no build deprecation for a built-in');
        self::assertNull($container->getDefinition('nowo_vault.port.access_checker')->getArgument(1));
    }

    #[Test]
    public function aServiceImplementingOnlyTheDeprecatedInterfaceIsAskedAsIn118WithADeprecation(): void
    {
        $container = $this->container('access_checker', 'app.ports', LegacyHostPorts::class);
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::LEGACY, $this->mode($container, 'access_checker'));
        self::assertFalse($container->hasAlias(VaultAccessPolicyInterface::class));
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('implements only the deprecated', $this->deprecations[0]);
        self::assertEquals(new Reference(VaultAccessCheckerInterface::class), $container->getDefinition('nowo_vault.port.access_checker')->getArgument(1));
    }

    #[Test]
    public function aServiceImplementingOnlyThe119InterfaceLosesTheDeprecatedAlias(): void
    {
        $container = $this->container('access_checker', 'app.ports', PolicyHostPorts::class);
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::POLICY, $this->mode($container, 'access_checker'));
        self::assertFalse($container->hasAlias(VaultAccessCheckerInterface::class), 'the deprecated alias is registered only for a class that implements it');
        self::assertSame('app.ports', (string) $container->getAlias(VaultAccessPolicyInterface::class));
    }

    #[Test]
    public function aServiceImplementingNeitherFailsTheBuild(): void
    {
        $container = $this->container('access_checker', 'app.ports', stdClass::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement');
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function aServiceOfUnknownClassKeepsThe118Wiring(): void
    {
        $container = $this->container('access_checker', 'app.ports', LegacyHostPorts::class);
        $container->getDefinition('app.ports')->setFactory([LegacyHostPorts::class, 'create']);
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::LEGACY, $this->mode($container, 'access_checker'));
        self::assertSame([], $this->deprecations);
    }

    #[Test]
    public function aMissingServiceKeepsThe118WiringAndLetsTheContainerReportIt(): void
    {
        $container = $this->container('access_checker', 'app.ports', LegacyHostPorts::class);
        $container->removeDefinition('app.ports');
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::LEGACY, $this->mode($container, 'access_checker'));
    }

    #[Test]
    public function aDecoratorOfTheDeprecatedAccessPortKeepsItAskedTogetherWithThe119One(): void
    {
        $container = $this->container('access_checker', 'app.ports', BothHostPorts::class);
        $this->decorate($container, VaultAccessCheckerInterface::class);
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::BOTH, $this->mode($container, 'access_checker'));
        self::assertStringContainsString('the weaker answer wins', $this->deprecations[0] ?? '');
    }

    #[Test]
    public function aDecoratorOfTheDeprecatedLocatorKeepsTheDeprecatedLocatorAsked(): void
    {
        $container = $this->container('user_locator', VaultUserLocator::class, VaultUserLocator::class, true);
        $this->decorate($container, VaultUserLocatorInterface::class);
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::LEGACY, $this->mode($container, 'user_locator'));
    }

    #[Test]
    public function aDecoratorOfTheDeprecatedPortOverA119OnlyServiceFailsTheBuild(): void
    {
        $container = $this->container('team_membership', 'app.ports', PolicyHostPorts::class);
        $this->decorate($container, VaultTeamMembershipResolverInterface::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not implement it');
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function aDecoratorOfThe119PortOverADeprecatedOnlyServiceFailsTheBuild(): void
    {
        $container = $this->container('team_membership', 'app.ports', LegacyHostPorts::class);
        $this->decorate($container, VaultTeamMembershipProviderInterface::class);
        $this->expectException(LogicException::class);
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function theApplicationsOwnAliasOfThe119InterfaceWinsOverABuiltIn(): void
    {
        $container = $this->container('team_membership', NullVaultTeamMembershipResolver::class, NullVaultTeamMembershipResolver::class, true);
        $container->register('app.teams', PolicyHostPorts::class);
        $container->setAlias(VaultTeamMembershipProviderInterface::class, 'app.teams');
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::POLICY, $this->mode($container, 'team_membership'));
        self::assertSame('app.teams', (string) $container->getAlias(VaultTeamMembershipProviderInterface::class));
        self::assertFalse($container->hasAlias(VaultTeamMembershipResolverInterface::class), 'its class does not implement the deprecated interface');

        $both = $this->container('team_membership', NullVaultTeamMembershipResolver::class, NullVaultTeamMembershipResolver::class, true);
        $both->register('app.teams', BothHostPorts::class);
        $both->setAlias(VaultTeamMembershipProviderInterface::class, 'app.teams');
        (new VaultPortsPass())->process($both);
        self::assertSame('app.teams', (string) $both->getAlias(VaultTeamMembershipResolverInterface::class));
    }

    #[Test]
    public function theApplicationsAliasAndAnotherConfiguredServiceFailTheBuild(): void
    {
        $container = $this->container('team_membership', 'app.ports', BothHostPorts::class);
        $container->register('app.teams', PolicyHostPorts::class);
        $container->setAlias(VaultTeamMembershipProviderInterface::class, 'app.teams');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('keep only one');
        (new VaultPortsPass())->process($container);
    }

    #[Test]
    public function theApplicationsAliasWithADeprecatedDecoratorIsBothForAccessPortsAndFailsOtherwise(): void
    {
        $container = $this->container('scope_access', NullVaultScopeAccessResolver::class, NullVaultScopeAccessResolver::class, true);
        $container->register('app.scopes', BothHostPorts::class);
        $container->setAlias('Nowo\\VaultBundle\\Security\\VaultScopeAccessProviderInterface', 'app.scopes');
        $this->decorate($container, 'Nowo\\VaultBundle\\Security\\VaultScopeAccessResolverInterface');
        (new VaultPortsPass())->process($container);
        self::assertSame(VaultPortMode::BOTH, $this->mode($container, 'scope_access'));
        self::assertSame('app.scopes', (string) $container->getAlias('Nowo\\VaultBundle\\Security\\VaultScopeAccessResolverInterface'));

        $locator = $this->container('user_locator', VaultUserLocator::class, VaultUserLocator::class, true);
        $locator->register('app.directory', PortsUserDirectoryV119::class);
        $locator->setAlias(VaultUserDirectoryInterface::class, 'app.directory');
        $this->decorate($locator, VaultUserLocatorInterface::class);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('asks only one of them');
        (new VaultPortsPass())->process($locator);
    }

    #[Test]
    public function theApplicationsOwnAliasOfTheDeprecatedInterfaceNamesTheService(): void
    {
        $container = $this->container('user_locator', VaultUserLocator::class, VaultUserLocator::class, true);
        $container->register('app.directory', PortsUserDirectory::class);
        $container->setAlias(VaultUserLocatorInterface::class, 'app.directory');
        (new VaultPortsPass())->process($container);

        self::assertSame(VaultPortMode::LEGACY, $this->mode($container, 'user_locator'));
        self::assertSame('app.directory', (string) $container->getAlias(VaultUserLocatorInterface::class));
        self::assertFalse($container->getAlias(VaultUserLocatorInterface::class)->isDeprecated(), 'the application alias is left as it is');
        self::assertStringContainsString('app.directory', $this->deprecations[0] ?? '');
    }

    #[Test]
    public function anInactivePortHasNoGate(): void
    {
        $container = $this->container('reauthentication', '', stdClass::class);
        (new VaultPortsPass())->process($container);

        self::assertFalse($container->hasDefinition('nowo_vault.port.reauthentication'));
        (new VaultPortsPass())->process(new ContainerBuilder());
    }

    private function container(string $port, string $service, string $class, bool $builtIn = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $legacy    = VaultPortsPass::PORTS[$port]['legacy'];
        if ($service !== '') {
            $container->register($service, $class);
            $container->setAlias($legacy, $service);
        }
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.service', $service);
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.built_in', $builtIn);
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.option', 'nowo_vault.' . $port);
        $container->setDefinition(VaultPortsPass::GATE_PREFIX . $port, new Definition(VaultPortsPass::PORTS[$port]['gate']));

        return $container;
    }

    private function decorate(ContainerBuilder $container, string $id): void
    {
        $container->register('app.audit.' . $id, PassThroughLegacyDecorators::class)
            ->setDecoratedService($id)
            ->setArguments([new Reference('app.audit.' . $id . '.inner')]);
    }

    private function mode(ContainerBuilder $container, string $port): string
    {
        return (string) $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . $port . '.mode');
    }
}
