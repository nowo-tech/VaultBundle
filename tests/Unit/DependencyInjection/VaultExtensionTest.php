<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Doctrine\VaultManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\EventListener\VaultConflictListener;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Internal\DeferredVaultEventDispatcher;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultExtensionTokenRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Ui\VaultPages;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
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

        self::assertSame('nowo_vault.access_checker.default', $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.service'));
        self::assertSame(ConfigurableVaultAccessChecker::class, $container->getDefinition('nowo_vault.access_checker.default')->getClass());
        self::assertSame(NullVaultTeamMembershipResolver::class, $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'team_membership.service'));
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

    public function testConflictAnswerWriteUnitAndDeferredDispatcherAreWired(): void
    {
        $container = $this->container(['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([self::BASE_CONFIG], $container);

        $listener = $container->getDefinition(VaultConflictListener::class);
        $policy   = $listener->getArgument(0);
        self::assertInstanceOf(Reference::class, $policy, 'unset options follow transaction-doctrine\'s policy service');
        self::assertSame(ConflictResponsePolicy::class, (string) $policy);
        self::assertSame(VaultExtension::COMMIT_LEDGER_SERVICE, (string) $listener->getArgument(1));
        self::assertTrue($container->hasDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertSame('bundle', $container->getParameter(VaultExtension::COMMIT_TRACKING_PARAMETER));
        self::assertTrue($container->hasDefinition(VaultWriteUnit::class));
        self::assertSame(VaultEntityManager::class, (string) $container->getDefinition(VaultWriteUnit::class)->getArgument(0), 'the private manager, the only mode since 2.0');
        foreach (VaultExtension::DEFERRED_EVENT_SERVICES as $service) {
            self::assertSame(DeferredVaultEventDispatcher::class, (string) $container->getDefinition($service)->getArgument('$eventDispatcher'), $service);
        }

        $custom = $this->container(['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([self::BASE_CONFIG + ['conflict_response' => ['status' => 503, 'commit_tracking' => 'off']]], $custom);
        $policy = $custom->getDefinition(VaultConflictListener::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([503, ConflictResponsePolicy::DEFAULT_RETRY_AFTER], $policy->getArguments());
        self::assertNull($custom->getDefinition(VaultConflictListener::class)->getArgument(1));
        self::assertFalse($custom->hasDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertFalse($custom->hasDefinition(VaultExtension::COMMIT_LEDGER_SERVICE));

        $retry = $this->container(['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([self::BASE_CONFIG + ['conflict_response' => ['retry_after' => 4]]], $retry);
        $policy = $retry->getDefinition(VaultConflictListener::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([ConflictResponsePolicy::STATUS_CONFLICT, 4], $policy->getArguments());
    }

    public function testConflictResponseStatusMustBe409Or503(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new VaultExtension())->load([self::BASE_CONFIG + ['conflict_response' => ['status' => 500]]], $this->container(['SecurityBundle' => SecurityBundle::class]));
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

        self::assertSame('app.access_checker', $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.service'));
        self::assertSame('app.team_resolver', $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'team_membership.service'));
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

        self::assertSame('nowo_vault.access_checker.allow_all', $container->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.service'));
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
        self::assertSame('app.checker', $custom->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.service'));

        $oneRole = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + ['security' => ['delete_roles' => ['ROLE_X']] + $empty]], $oneRole);
        self::assertSame('nowo_vault.access_checker.default', $oneRole->getParameter(VaultPortsPass::PARAMETER_PREFIX . 'access_checker.service'));
    }

    public function testWebUiParameters(): void
    {
        $container = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + ['web_ui' => ['enabled' => false, 'layout_template' => 'base.html.twig', 'templates' => ['items' => 'vault/items.html.twig'], 'path_prefix' => '/admin']]], $container);

        self::assertFalse($container->hasParameter('nowo_vault.css_framework'), 'the deprecated css_framework parameter was removed in 2.0');
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

        // REQ-UI-003: the CSS stack is inherited from nowo_ui_kit; the vault seeds only its shared-confirm table partial.
        self::assertSame(
            [['theme' => ['bundles' => [VaultPages::BUNDLE => ['partials' => ['table' => VaultPages::TABLE_PARTIAL]]]]]],
            $container->getExtensionConfig('nowo_ui_kit'),
        );
        self::assertSame(['class' => 'nowo-ui-input'], $formKit['profiles']['vault']['defaults']['attr']);
    }

    public function testThePrivateEntityManagerIsTheOnlyMode(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $type, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $default = $this->container(['SecurityBundle' => SecurityBundle::class]);
            (new VaultExtension())->load([self::BASE_CONFIG], $default);
            self::assertSame([], $deprecations, 'unset: no deprecation since 2.0');
            self::assertFalse($default->hasParameter('nowo_vault.private_entity_manager'));
            self::assertTrue($default->hasDefinition(VaultEntityManager::class));
            self::assertSame('default', $default->getDefinition(VaultEntityManager::class)->getArgument(1));
            self::assertSame(VaultEntityManager::class, (string) $default->getDefinition(DoctrineOrmVaultItemRepository::class)->getArgument('$entityManager'));
            self::assertSame(VaultManagerRegistry::class, (string) $default->getDefinition(DoctrineOrmVaultItemRepository::class)->getArgument('$registry'));
            self::assertSame(VaultManagerRegistry::class, (string) $default->getDefinition(VaultPersonalDataEraser::class)->getArgument('$registry'));
            self::assertSame(VaultEntityManager::class, (string) $default->getDefinition(VaultItemFormType::class)->getArgument('$folderManager'));
            self::assertEquals([new Reference(VaultEntityManager::class), new Reference(DeferredVaultEventDispatcher::class), new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)], $default->getDefinition(VaultWriteUnit::class)->getArguments());

            // Q4: true stays valid in 2.x as a no-op, with a deprecation (removed in 3.0).
            $private = $this->container(['SecurityBundle' => SecurityBundle::class]);
            (new VaultExtension())->load([self::BASE_CONFIG, ['database' => ['private_entity_manager' => true, 'entity_manager' => 'vault']]], $private);
            self::assertSame('vault', $private->getDefinition(VaultEntityManager::class)->getArgument(1));
            self::assertCount(1, $deprecations);
            self::assertStringContainsString('The option has no effect since 2.0 (the private manager is the only mode); remove it.', $deprecations[0]);
        } finally {
            restore_error_handler();
        }

        try {
            (new VaultExtension())->load([self::BASE_CONFIG, ['database' => ['private_entity_manager' => false]]], $this->container(['SecurityBundle' => SecurityBundle::class]));
            self::fail('false must fail the build');
        } catch (InvalidConfigurationException $exception) {
            self::assertStringContainsString('private_entity_manager: false was removed in 2.0', $exception->getMessage());
            self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
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

    public function testPrependRegistersTheRowActionsPartialOnlyWithTheMenuOption(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $container->registerExtension($this->fakeExtension('nowo_vault'));
        $container->prependExtensionConfig('nowo_vault', ['web_ui' => ['destructive_actions_in_menu' => false]]);
        $container->prependExtensionConfig('nowo_vault', ['web_ui' => ['destructive_actions_in_menu' => true]]);
        $container->prependExtensionConfig('nowo_vault', ['web_ui' => []]);

        (new VaultExtension())->prepend($container);
        self::assertSame(
            [['theme' => ['bundles' => [VaultPages::BUNDLE => ['partials' => ['table' => VaultPages::TABLE_PARTIAL]]]]]],
            $container->getExtensionConfig('nowo_ui_kit'),
            'The last value (false) wins: only the shared-confirm table partial.',
        );

        $on = new ContainerBuilder();
        $on->registerExtension($this->fakeExtension('nowo_ui_kit'));
        $on->registerExtension($this->fakeExtension('nowo_vault'));
        $on->prependExtensionConfig('nowo_vault', ['web_ui' => ['destructive_actions_in_menu' => true]]);

        (new VaultExtension())->prepend($on);
        self::assertSame(
            ['theme' => ['bundles' => [VaultPages::BUNDLE => ['partials' => ['table' => VaultPages::TABLE_PARTIAL, 'row_actions' => VaultPages::ROW_ACTIONS_PARTIAL]]]]],
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
        self::assertSame('buttons', $default->getParameter('nowo_vault.web_ui.header_actions'));
        self::assertSame([], $default->getParameter('nowo_vault.unmapped_entity_classes'));
        self::assertSame([], $default->getParameter('nowo_vault.unmapped_tables'));

        $container = $this->securedContainer();
        (new VaultExtension())->load([self::BASE_CONFIG + [
            'table_prefix' => 'pw_',
            'sharing'      => ['enabled' => false],
            'web_ui'       => ['host_note_template' => 'vault/_note.html.twig', 'destructive_actions_in_menu' => true, 'header_actions' => 'page_actions'],
            'database'     => ['conditional_mapping' => true],
        ]], $container);
        self::assertFalse($container->getParameter('nowo_vault.sharing.enabled'));
        self::assertSame('vault/_note.html.twig', $container->getParameter('nowo_vault.web_ui.host_note_template'));
        self::assertTrue($container->getParameter('nowo_vault.web_ui.destructive_actions_in_menu'));
        self::assertSame('page_actions', $container->getParameter('nowo_vault.web_ui.header_actions'));
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
