<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Doctrine\VaultManagerRegistry;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultExtensionTokenRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Ui\VaultPages;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function json_encode;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

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

    public function testEveryRoleListEmptyFailsAtCompileTime(): void
    {
        $empty = ['admin_roles' => [], 'access_roles' => [], 'create_roles' => [], 'list_roles' => [], 'delete_roles' => []];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('every role list');

        (new VaultExtension())->load([self::BASE_CONFIG + ['security' => $empty]], $this->securedContainer());
    }

    public function testEmptyRoleListsAreAcceptedWithACustomCheckerOrOneRole(): void
    {
        $empty = ['admin_roles' => [], 'access_roles' => [], 'create_roles' => [], 'list_roles' => [], 'delete_roles' => []];

        $custom = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + ['security' => $empty + ['access_checker' => 'app.checker']]], $custom);
        self::assertSame('app.checker', (string) $custom->getAlias(VaultAccessCheckerInterface::class));

        $oneRole = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + ['security' => ['delete_roles' => ['ROLE_X']] + $empty]], $oneRole);
        self::assertSame('nowo_vault.access_checker.default', (string) $oneRole->getAlias(VaultAccessCheckerInterface::class));
    }

    public function testWebUiParametersAndDeprecatedCssFramework(): void
    {
        $container = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + ['css_framework' => 'bootstrap', 'web_ui' => ['enabled' => false, 'layout_template' => 'base.html.twig', 'templates' => ['items' => 'vault/items.html.twig'], 'path_prefix' => '/admin']]], $container);

        self::assertSame('bootstrap5', $container->getParameter('nowo_vault.css_framework'));
        self::assertFalse($container->getParameter('nowo_vault.web_ui.enabled'));
        self::assertSame('base.html.twig', $container->getParameter('nowo_vault.web_ui.layout_template'));
        self::assertSame(['items' => 'vault/items.html.twig'], $container->getParameter('nowo_vault.web_ui.templates'));
        /** @var array{route_prefix: string, templates: array<string, string|null>} $baseline */
        $baseline = $container->getParameter('nowo_vault.runtime_config.yaml_baseline');
        self::assertSame('/admin', $baseline['route_prefix']);
        self::assertSame('base.html.twig', $baseline['templates']['layout']);
        self::assertSame('vault/items.html.twig', $baseline['templates']['items']);
        self::assertSame('@NowoVaultBundle/vault/home.html.twig', $baseline['templates']['index']);

        $default = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG], $default);
        self::assertSame('tabler', $default->getParameter('nowo_vault.css_framework'));
        self::assertTrue($default->getParameter('nowo_vault.web_ui.enabled'));
        self::assertNull($default->getParameter('nowo_vault.web_ui.layout_template'));
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

        // REQ-UI-003: the CSS stack is inherited from nowo_ui_kit; nothing is seeded without the deprecated key.
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'));
        self::assertSame(['class' => 'nowo-ui-input'], $formKit['profiles']['vault']['defaults']['attr']);
    }

    public function testPrivateEntityManagerIsOptInAndTheDefaultIsDeprecated(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $default = $this->container(['SecurityBundle' => SecurityBundle::class]);
            (new VaultExtension())->load([self::BASE_CONFIG], $default);
            self::assertFalse($default->getParameter('nowo_vault.private_entity_manager'));
            self::assertFalse($default->hasDefinition(VaultEntityManager::class), 'unset keeps the 1.18 wiring');
            self::assertSame('doctrine.orm.default_entity_manager', (string) $default->getDefinition(DoctrineOrmVaultItemRepository::class)->getArgument('$entityManager'));
            self::assertStringContainsString('Not setting "nowo_vault.database.private_entity_manager" is deprecated', $deprecations[0] ?? '');

            $private = $this->container(['SecurityBundle' => SecurityBundle::class]);
            (new VaultExtension())->load([self::BASE_CONFIG, ['database' => ['private_entity_manager' => true, 'entity_manager' => 'vault']]], $private);
            self::assertTrue($private->hasDefinition(VaultEntityManager::class));
            self::assertSame('vault', $private->getDefinition(VaultEntityManager::class)->getArgument(1));
            self::assertSame(VaultEntityManager::class, (string) $private->getDefinition(DoctrineOrmVaultItemRepository::class)->getArgument('$entityManager'));
            self::assertSame(VaultManagerRegistry::class, (string) $private->getDefinition(DoctrineOrmVaultItemRepository::class)->getArgument('$registry'));
            self::assertSame(VaultManagerRegistry::class, (string) $private->getDefinition(VaultPersonalDataEraser::class)->getArgument('$registry'));
            self::assertSame(VaultEntityManager::class, (string) $private->getDefinition(VaultItemFormType::class)->getArgument('$folderManager'));
            self::assertCount(1, $deprecations, 'true: no deprecation');

            (new VaultExtension())->load([self::BASE_CONFIG, ['database' => ['private_entity_manager' => false]]], $this->container(['SecurityBundle' => SecurityBundle::class]));
            self::assertStringContainsString('private_entity_manager: false" is deprecated', $deprecations[1] ?? '');
        } finally {
            restore_error_handler();
        }

        $this->expectException(InvalidConfigurationException::class);
        (new VaultExtension())->load([self::BASE_CONFIG, ['database' => ['private_entity_manager' => 'yes']]], $this->container(['SecurityBundle' => SecurityBundle::class]));
    }

    public function testPrependMapsTheXmlMappingOrNothing(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('doctrine'));
        $container->prependExtensionConfig('nowo_vault', ['database' => ['mapping_type' => 'xml']]);
        (new VaultExtension())->prepend($container);
        $mapping = $container->getExtensionConfig('doctrine')[0]['orm']['mappings']['VaultBundle'];
        self::assertSame(['type' => 'xml', 'is_bundle' => false, 'dir' => VaultExtension::XML_MAPPING_DIR, 'prefix' => 'Nowo\\VaultBundle\\Entity'], $mapping);

        $none = new ContainerBuilder();
        $none->registerExtension($this->fakeExtension('doctrine'));
        $none->prependExtensionConfig('nowo_vault', ['database' => ['register_mapping' => false]]);
        (new VaultExtension())->prepend($none);
        self::assertSame([], $none->getExtensionConfig('doctrine'));
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
        $container->registerExtension($this->fakeExtension('nowo_vault'));
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tailwind']);
        $container->prependExtensionConfig('nowo_vault', ['css_framework' => 'tabler']);

        (new VaultExtension())->prepend($container);

        self::assertSame(['icon_set' => 'tabler-icons'], $container->getExtensionConfig('nowo_ui_kit')[0]);
    }

    public function testPrependLeavesUiKitAloneWhenHostSetBothKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $container->registerExtension($this->fakeExtension('nowo_vault'));
        $container->prependExtensionConfig('nowo_ui_kit', ['icon_set' => 'bootstrap-icons']);
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'tailwind']);
        $container->prependExtensionConfig('nowo_vault', ['css_framework' => 'tabler']);

        (new VaultExtension())->prepend($container);

        self::assertCount(2, $container->getExtensionConfig('nowo_ui_kit'));
    }

    public function testPrependRegistersTheRowActionsPartialOnlyWithTheMenuOption(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $container->registerExtension($this->fakeExtension('nowo_vault'));
        $container->prependExtensionConfig('nowo_vault', ['web_ui' => ['destructive_actions_in_menu' => false]]);
        $container->prependExtensionConfig('nowo_vault', ['web_ui' => ['destructive_actions_in_menu' => true]]);
        $container->prependExtensionConfig('nowo_vault', ['web_ui' => []]);

        (new VaultExtension())->prepend($container);
        self::assertSame([], $container->getExtensionConfig('nowo_ui_kit'), 'The last value (false) wins.');

        $on = new ContainerBuilder();
        $on->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $on->registerExtension($this->fakeExtension('nowo_vault'));
        $on->prependExtensionConfig('nowo_vault', ['web_ui' => ['destructive_actions_in_menu' => true]]);

        (new VaultExtension())->prepend($on);
        self::assertSame(
            ['theme' => ['bundles' => [VaultPages::BUNDLE => ['partials' => ['row_actions' => VaultPages::ROW_ACTIONS_PARTIAL]]]]],
            $on->getExtensionConfig('nowo_ui_kit')[0],
        );
    }

    public function testBeaconAdoptionParameters(): void
    {
        $default = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG], $default);
        self::assertTrue($default->getParameter('nowo_vault.sharing.enabled'));
        self::assertNull($default->getParameter('nowo_vault.web_ui.host_note_template'));
        self::assertFalse($default->getParameter('nowo_vault.web_ui.destructive_actions_in_menu'));
        self::assertSame([], $default->getParameter('nowo_vault.unmapped_entity_classes'));
        self::assertSame([], $default->getParameter('nowo_vault.unmapped_tables'));

        $container = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + [
            'table_prefix' => 'pw_',
            'sharing'      => ['enabled' => false],
            'web_ui'       => ['host_note_template' => 'vault/_note.html.twig', 'destructive_actions_in_menu' => true],
            'database'     => ['conditional_mapping' => true],
        ]], $container);
        self::assertFalse($container->getParameter('nowo_vault.sharing.enabled'));
        self::assertSame('vault/_note.html.twig', $container->getParameter('nowo_vault.web_ui.host_note_template'));
        self::assertTrue($container->getParameter('nowo_vault.web_ui.destructive_actions_in_menu'));
        self::assertSame([VaultSettings::class, VaultExtensionToken::class], $container->getParameter('nowo_vault.unmapped_entity_classes'));
        self::assertSame(['pw_settings', 'pw_extension_tokens'], $container->getParameter('nowo_vault.unmapped_tables'));

        $featuresOn = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + [
            'database'          => ['conditional_mapping' => true],
            'config_storage'    => ['enabled' => true],
            'browser_extension' => ['enabled' => true],
        ]], $featuresOn);
        self::assertSame([], $featuresOn->getParameter('nowo_vault.unmapped_entity_classes'));
        self::assertSame([], $featuresOn->getParameter('nowo_vault.unmapped_tables'));
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
