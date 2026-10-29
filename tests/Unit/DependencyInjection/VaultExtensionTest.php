<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultExtensionTokenRepository;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function json_encode;

final class VaultExtensionTest extends TestCase
{
    private const BASE_CONFIG = [
        'user_class'     => 'App\\Entity\\User',
        'encryption_key' => 'REDACTED-TEST',
    ];

    public function testLoadRequiresSecurityBundleUnlessUnauthenticatedAccessIsAllowed(): void
    {
        $container = $this->container();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');

        (new VaultExtension())->load([self::BASE_CONFIG], $container);
    }

    public function testLoadDetectsSecurityBundleFromKernelBundles(): void
    {
        $container = $this->container(['SecurityBundle' => SecurityBundle::class]);

        (new VaultExtension())->load([self::BASE_CONFIG], $container);

        self::assertSame('nowo_vault.access_checker.default', (string) $container->getAlias(VaultAccessCheckerInterface::class));
        self::assertSame(ConfigurableVaultAccessChecker::class, $container->getDefinition('nowo_vault.access_checker.default')->getClass());
        self::assertSame(NullVaultTeamMembershipResolver::class, (string) $container->getAlias(VaultTeamMembershipResolverInterface::class));
        self::assertSame('vault_items', $container->getParameter('nowo_vault.items_table'));
        self::assertSame('nowo_vault', (new VaultExtension())->getAlias());
    }

    public function testLoadExposesPaginationLoginCapAndClockLoggerWiring(): void
    {
        $container = $this->container(['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([self::BASE_CONFIG], $container);

        self::assertSame(50, $container->getParameter('nowo_vault.pagination.per_page'));
        self::assertSame(1000, $container->getParameter('nowo_vault.browser_extension.max_login_items'));

        $clock = $container->getDefinition(DoctrineOrmVaultExtensionTokenRepository::class)->getArgument('$clock');
        self::assertInstanceOf(Reference::class, $clock);
        self::assertSame(ClockInterface::class, (string) $clock);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $clock->getInvalidBehavior());

        $logger = $container->getDefinition(VaultRuntimeConfigWriter::class)->getArgument('$logger');
        self::assertInstanceOf(Reference::class, $logger);
        self::assertSame('logger', (string) $logger);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $logger->getInvalidBehavior());

        $custom = $this->container(['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([self::BASE_CONFIG + [
            'pagination'        => ['per_page' => 25],
            'browser_extension' => ['max_login_items' => 10],
        ]], $custom);
        self::assertSame(25, $custom->getParameter('nowo_vault.pagination.per_page'));
        self::assertSame(10, $custom->getParameter('nowo_vault.browser_extension.max_login_items'));
    }

    public function testPaginationAndLoginCapBoundsAreValidated(): void
    {
        foreach ([
            ['pagination' => ['per_page' => 0]],
            ['pagination' => ['per_page' => 1001]],
            ['browser_extension' => ['max_login_items' => 0]],
        ] as $invalid) {
            try {
                (new VaultExtension())->load([self::BASE_CONFIG + $invalid], $this->container(['SecurityBundle' => SecurityBundle::class]));
                self::fail('Expected rejection of ' . json_encode($invalid));
            } catch (InvalidConfigurationException $exception) {
                self::assertMatchesRegularExpression('/per_page|max_login_items/', $exception->getMessage());
            }
        }
    }

    public function testLoadWithoutKernelBundlesParameterStillRequiresSecurity(): void
    {
        $container = new ContainerBuilder();

        $this->expectException(LogicException::class);

        (new VaultExtension())->load([self::BASE_CONFIG], $container);
    }

    public function testLoadDetectsRegisteredSecurityExtension(): void
    {
        $container = $this->container([]);
        $container->registerExtension($this->fakeExtension('security'));

        (new VaultExtension())->load([self::BASE_CONFIG + [
            'team_membership_resolver' => 'app.team_resolver',
            'security'                 => ['access_checker' => 'app.access_checker'],
        ]], $container);

        self::assertSame('app.access_checker', (string) $container->getAlias(VaultAccessCheckerInterface::class));
        self::assertSame('app.team_resolver', (string) $container->getAlias(VaultTeamMembershipResolverInterface::class));
    }

    public function testAllowUnauthenticatedIsRejectedInProd(): void
    {
        $container = $this->container([], 'prod');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must not be enabled in the prod environment');

        (new VaultExtension())->load([self::BASE_CONFIG + ['security' => ['allow_unauthenticated' => true]]], $container);
    }

    public function testAllowUnauthenticatedOutsideProdUsesAllowAllChecker(): void
    {
        $container = $this->container([], 'dev');

        (new VaultExtension())->load([self::BASE_CONFIG + ['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertSame('nowo_vault.access_checker.allow_all', (string) $container->getAlias(VaultAccessCheckerInterface::class));
        self::assertSame(AllowAllVaultAccessChecker::class, $container->getDefinition('nowo_vault.access_checker.allow_all')->getClass());
    }

    public function testDefaultAuthenticatorAutowiresProviderAndDefersUserCheckerToFirewall(): void
    {
        $container = $this->securedContainer();

        (new VaultExtension())->load([self::BASE_CONFIG + ['browser_extension' => ['firewall' => 'main']]], $container);

        $definition = $container->getDefinition(DefaultVaultBrowserExtensionAuthenticator::class);
        self::assertTrue($definition->isAutowired());
        self::assertSame([['firewall' => 'main']], $definition->getTag(BrowserExtensionUserCheckerPass::TAG));
        self::assertSame(DefaultVaultBrowserExtensionAuthenticator::class, (string) $container->getAlias(VaultBrowserExtensionAuthenticatorInterface::class));
    }

    public function testDefaultAuthenticatorUsesConfiguredProviderAndChecker(): void
    {
        $container = $this->securedContainer();

        (new VaultExtension())->load([self::BASE_CONFIG + ['browser_extension' => [
            'user_provider' => 'app.user_provider',
            'user_checker'  => 'app.user_checker',
        ]]], $container);

        $definition = $container->getDefinition(DefaultVaultBrowserExtensionAuthenticator::class);
        self::assertFalse($definition->isAutowired());
        self::assertEquals(new Reference('app.user_provider'), $definition->getArgument('$userProvider'));
        self::assertEquals(new Reference('app.user_checker'), $definition->getArgument('$userChecker'));
        self::assertFalse($definition->hasTag(BrowserExtensionUserCheckerPass::TAG));
    }

    public function testCustomAuthenticatorIsAliasedWithoutDefaultDefinitionOverride(): void
    {
        $container = $this->securedContainer();

        (new VaultExtension())->load([self::BASE_CONFIG + ['browser_extension' => ['authenticator' => 'app.authenticator']]], $container);

        self::assertSame('app.authenticator', (string) $container->getAlias(VaultBrowserExtensionAuthenticatorInterface::class));
    }

    public function testPrependWithoutOptionalExtensionsDoesNothing(): void
    {
        $container = new ContainerBuilder();

        (new VaultExtension())->prepend($container);

        foreach (['framework', 'doctrine', 'nowo_form_kit', 'nowo_ui_kit'] as $name) {
            self::assertSame([], $container->getExtensionConfig($name));
        }
    }

    public function testPrependSeedsFrameworkDoctrineFormKitAndUiKitDefaults(): void
    {
        $container = new ContainerBuilder();
        foreach (['framework', 'doctrine', 'nowo_form_kit', 'nowo_ui_kit'] as $name) {
            $container->registerExtension($this->fakeExtension($name));
        }

        (new VaultExtension())->prepend($container);

        self::assertSame('/bundles/vault', $container->getExtensionConfig('framework')[0]['assets']['packages']['nowo_vault']['base_path']);
        self::assertSame('attribute', $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['VaultBundle']['type']);

        $formKit = $container->getExtensionConfig('nowo_form_kit')[0];
        self::assertSame('bootstrap', $formKit['css_framework']);
        self::assertSame('NowoVaultBundle', $formKit['profiles']['vault']['translation_domain']);
        self::assertTrue($formKit['profiles']['vault']['convention_requires_translation']);

        // Root css_framework defaults to tabler.
        self::assertSame(['css_framework' => 'tabler', 'icon_set' => 'tabler-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testPrependRespectsHostFormKitSettings(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_form_kit', ['css_framework' => 'tailwind', 'profiles' => ['vault' => ['alias' => 'host']]]);

        (new VaultExtension())->prepend($container);

        self::assertSame([['css_framework' => 'tailwind', 'profiles' => ['vault' => ['alias' => 'host']]]], $container->getExtensionConfig('nowo_form_kit'));
    }

    public function testPrependSeedsOnlyMissingFormKitKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_form_kit', ['css_framework' => 'tailwind']);

        (new VaultExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertCount(2, $configs);
        self::assertArrayNotHasKey('css_framework', $configs[0]);
        self::assertArrayHasKey('vault', $configs[0]['profiles']);
    }

    public function testPrependMapsBootstrapToBootstrap5ForUiKit(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $container->registerExtension($this->fakeExtension('nowo_vault'));
        $container->prependExtensionConfig('nowo_vault', ['css_framework' => 'bootstrap']);

        (new VaultExtension())->prepend($container);

        self::assertSame(['css_framework' => 'bootstrap5', 'icon_set' => 'bootstrap-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testPrependSeedsOnlyMissingUiKitKey(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tailwind']);

        (new VaultExtension())->prepend($container);

        self::assertSame(['icon_set' => 'tabler-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testPrependLeavesUiKitAloneWhenHostSetBothKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_ui_kit', ['icon_set' => 'bootstrap-icons']);
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tailwind']);

        (new VaultExtension())->prepend($container);

        self::assertCount(2, $container->getExtensionConfig('nowo_ui_kit'));
    }

    /**
     * @param array<string, string>|null $bundles
     */
    private function container(?array $bundles = [], string $environment = 'test'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', $environment);
        if ($bundles !== null) {
            $container->setParameter('kernel.bundles', $bundles);
        }

        return $container;
    }

    private function securedContainer(): ContainerBuilder
    {
        $container = $this->container();
        $container->registerExtension($this->fakeExtension('security'));

        return $container;
    }

    private function fakeExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
