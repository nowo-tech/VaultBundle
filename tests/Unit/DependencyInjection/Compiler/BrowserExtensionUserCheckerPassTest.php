<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class BrowserExtensionUserCheckerPassTest extends TestCase
{
    public function testNoopWithoutAuthenticatorDefinition(): void
    {
        $container = new ContainerBuilder();

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertFalse($container->hasDefinition(DefaultVaultBrowserExtensionAuthenticator::class));
    }

    public function testExplicitUserCheckerIsKept(): void
    {
        $container  = $this->container(['main']);
        $definition = (new Definition(DefaultVaultBrowserExtensionAuthenticator::class))
            ->setArgument('$userChecker', new Reference('app.checker'));
        $container->setDefinition(DefaultVaultBrowserExtensionAuthenticator::class, $definition);

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertSame('app.checker', (string) $definition->getArgument('$userChecker'));
    }

    public function testAutoPrefersMainFirewall(): void
    {
        $container  = $this->container(['api', 'main']);
        $definition = $this->authenticator($container, null);

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertSame('security.user_checker.main', (string) $definition->getArgument('$userChecker'));
        self::assertFalse($definition->hasTag(BrowserExtensionUserCheckerPass::TAG));
    }

    public function testAutoFallsBackToFirstFirewallWithSecurityEnabled(): void
    {
        // "dev" has security: false, so SecurityBundle registers no checker alias for it.
        $container  = $this->container(['admin', 'api'], ['dev', 'admin', 'api']);
        $definition = $this->authenticator($container, null);

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertSame('security.user_checker.admin', (string) $definition->getArgument('$userChecker'));
    }

    public function testAutoKeepsFallbackWithoutFirewalls(): void
    {
        $container  = new ContainerBuilder();
        $definition = $this->authenticator($container, null);

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertSame('fallback', (string) $definition->getArgument('$userChecker'));
    }

    public function testAutoIgnoresNonArrayFirewallsParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('security.firewalls', 'main');
        $definition = $this->authenticator($container, null);

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertSame('fallback', (string) $definition->getArgument('$userChecker'));
    }

    public function testConfiguredFirewallIsUsed(): void
    {
        $container  = $this->container(['main', 'api']);
        $definition = $this->authenticator($container, 'api');

        (new BrowserExtensionUserCheckerPass())->process($container);

        self::assertSame('security.user_checker.api', (string) $definition->getArgument('$userChecker'));
    }

    public function testUnknownConfiguredFirewallThrows(): void
    {
        $container = $this->container(['main']);
        $this->authenticator($container, 'missing');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"missing" is not a firewall with security enabled (known firewalls: main)');

        (new BrowserExtensionUserCheckerPass())->process($container);
    }

    public function testUnknownConfiguredFirewallWithoutSecurityThrows(): void
    {
        $container = new ContainerBuilder();
        $this->authenticator($container, 'main');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('(known firewalls: none)');

        (new BrowserExtensionUserCheckerPass())->process($container);
    }

    /**
     * @param list<string> $securedFirewalls firewalls that get a security.user_checker.<name> alias
     * @param list<mixed>|null $allFirewalls value of the security.firewalls parameter
     */
    private function container(array $securedFirewalls, ?array $allFirewalls = null): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('security.firewalls', $allFirewalls ?? $securedFirewalls);
        $container->setDefinition('checker', new Definition(stdClass::class));
        foreach ($securedFirewalls as $name) {
            $container->setAlias('security.user_checker.' . $name, 'checker');
        }

        return $container;
    }

    private function authenticator(ContainerBuilder $container, ?string $firewall): Definition
    {
        $definition = (new Definition(DefaultVaultBrowserExtensionAuthenticator::class))
            ->setArgument('$userChecker', new Reference('fallback'))
            ->addTag(BrowserExtensionUserCheckerPass::TAG, ['firewall' => $firewall]);
        $container->setDefinition(DefaultVaultBrowserExtensionAuthenticator::class, $definition);

        return $definition;
    }
}
