<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionLoginRateLimiter;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Integration\TagInputIntegration;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultExtensionTokenRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultFolderRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultGrantRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultSettingsRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultTagRepository;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\RuntimeKeyVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\User\UserCheckerInterface;

use function array_key_exists;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Loads the bundle configuration, registers parameters and wires repositories, security and integrations.
 */
final class VaultExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Processes the `nowo_vault` configuration and registers the bundle services and parameters.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        // Load the resource-scanned services first: the explicit definitions below (default
        // browser-extension authenticator, response factory) must not be replaced by autowired
        // prototypes, or their configured arguments (user_provider, user_checker, environment) are lost.
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        if (
            !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('VaultBundle manage UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $prefix               = rtrim((string) $config['table_prefix'], '_');
        $itemsTable           = $prefix . '_items';
        $foldersTable         = $prefix . '_folders';
        $grantsTable          = $prefix . '_grants';
        $tagsTable            = $prefix . '_tags';
        $itemTagsTable        = $prefix . '_item_tag';
        $settingsTable        = $prefix . '_settings';
        $extensionTokensTable = $prefix . '_extension_tokens';
        $database             = $config['database'];
        $emName               = (string) $database['entity_manager'];
        $configStorage        = $config['config_storage'];
        $runtimeBaseline      = RuntimeConfiguration::extractBaseline($config);

        $container->setParameter('nowo_vault.user_class', $config['user_class']);
        // Deprecated since 1.15 (REQ-UI-003: the pages read the CSS stack from nowo_ui_kit); kept for the deprecated
        // nowo_vault_css_framework Twig global.
        $container->setParameter('nowo_vault.css_framework', $this->cssFramework($config['css_framework']) ?? 'tabler');
        $container->setParameter('nowo_vault.web_ui.enabled', (bool) $config['web_ui']['enabled']);
        $container->setParameter('nowo_vault.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_vault.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_vault.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_vault.items_table', $itemsTable);
        $container->setParameter('nowo_vault.folders_table', $foldersTable);
        $container->setParameter('nowo_vault.grants_table', $grantsTable);
        $container->setParameter('nowo_vault.tags_table', $tagsTable);
        $container->setParameter('nowo_vault.item_tags_table', $itemTagsTable);
        $container->setParameter('nowo_vault.settings_table', $settingsTable);
        $container->setParameter('nowo_vault.extension_tokens_table', $extensionTokensTable);
        $browserExtension = $config['browser_extension'];
        $container->setParameter('nowo_vault.browser_extension.enabled', (bool) $browserExtension['enabled']);
        $container->setParameter('nowo_vault.browser_extension.token_ttl', (int) $browserExtension['token_ttl']);
        $container->setParameter('nowo_vault.browser_extension.max_login_items', (int) $browserExtension['max_login_items']);
        $container->setParameter('nowo_vault.pagination.per_page', (int) $config['pagination']['per_page']);
        $container->setParameter('nowo_vault.browser_extension.routes', $browserExtension['routes']);
        $container->setParameter('nowo_vault.browser_extension.cors_allowed_origins', $browserExtension['cors_allowed_origins']);
        $loginRateLimit = $browserExtension['login_rate_limit'];
        $container->setDefinition(VaultBrowserExtensionLoginRateLimiter::class, (new Definition(VaultBrowserExtensionLoginRateLimiter::class))
            ->setAutowired(false)
            ->setArgument('$cache', new Reference((string) $loginRateLimit['cache_pool']))
            ->setArgument('$maxAttempts', (int) $loginRateLimit['max_attempts'])
            ->setArgument('$intervalSeconds', (int) $loginRateLimit['interval_seconds'])
            ->setArgument('$enabled', (bool) $loginRateLimit['enabled'])
            ->setArgument('$maxAttemptsPerIp', (int) $loginRateLimit['max_attempts_per_ip']));
        $container->setParameter('nowo_vault.database', $database);
        $container->setParameter('nowo_vault.config_storage', $configStorage);
        $container->setParameter('nowo_vault.config_storage.enabled', (bool) $configStorage['enabled']);
        $container->setParameter('nowo_vault.runtime_config.yaml_baseline', $runtimeBaseline);
        $container->setParameter('nowo_vault.password_strength_enabled', PasswordStrengthIntegration::isAvailable());
        $container->setParameter('nowo_vault.tag_input_enabled', TagInputIntegration::isAvailable());

        $teamResolverId = $config['team_membership_resolver'] ?? null;
        if (!is_string($teamResolverId) || $teamResolverId === '') {
            $teamResolverId = NullVaultTeamMembershipResolver::class;
            $container->setDefinition(NullVaultTeamMembershipResolver::class, new Definition(NullVaultTeamMembershipResolver::class));
        }
        $container->setAlias(VaultTeamMembershipResolverInterface::class, $teamResolverId);

        $emRef     = new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));
        $cachePool = (string) $configStorage['cache_pool'];

        foreach ([
            DoctrineOrmVaultItemRepository::class           => VaultItemRepositoryInterface::class,
            DoctrineOrmVaultFolderRepository::class         => VaultFolderRepositoryInterface::class,
            DoctrineOrmVaultGrantRepository::class          => VaultGrantRepositoryInterface::class,
            DoctrineOrmVaultTagRepository::class            => VaultTagRepositoryInterface::class,
            DoctrineOrmVaultSettingsRepository::class       => VaultSettingsRepositoryInterface::class,
            DoctrineOrmVaultExtensionTokenRepository::class => VaultExtensionTokenRepositoryInterface::class,
        ] as $repoClass => $interface) {
            $container->setDefinition($repoClass, (new Definition($repoClass))
                ->setAutowired(false)
                ->setArgument('$entityManager', $emRef)
                ->setArgument('$registry', new Reference('doctrine'))
                ->setArgument('$managerName', $emName));
            $container->setAlias($interface, $repoClass);
        }

        // Token expiry queries read the host clock when one is registered (REQ-DI-001).
        $container->getDefinition(DoctrineOrmVaultExtensionTokenRepository::class)
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));

        $container->setDefinition(VaultMetadataListener::class, (new Definition(VaultMetadataListener::class))
            ->setArgument('$itemsTableName', $itemsTable)
            ->setArgument('$foldersTableName', $foldersTable)
            ->setArgument('$grantsTableName', $grantsTable)
            ->setArgument('$tagsTableName', $tagsTable)
            ->setArgument('$itemTagsTableName', $itemTagsTable)
            ->setArgument('$settingsTableName', $settingsTable)
            ->setArgument('$extensionTokensTableName', $extensionTokensTable)
            ->setArgument('$userClass', $config['user_class'])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $container->setDefinition(VaultRuntimeConfigProvider::class, (new Definition(VaultRuntimeConfigProvider::class))
            ->setAutowired(false)
            ->setArgument('$yamlBaseline', $runtimeBaseline)
            ->setArgument('$databaseEnabled', (bool) $configStorage['enabled'])
            ->setArgument('$settingsRepository', new Reference(VaultSettingsRepositoryInterface::class))
            ->setArgument('$cache', new Reference($cachePool)));

        $container->setDefinition(VaultRuntimeConfigWriter::class, (new Definition(VaultRuntimeConfigWriter::class))
            ->setAutowired(false)
            ->setArgument('$databaseEnabled', (bool) $configStorage['enabled'])
            ->setArgument('$settingsRepository', new Reference(VaultSettingsRepositoryInterface::class))
            ->setArgument('$configProvider', new Reference(VaultRuntimeConfigProvider::class))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)));

        $container->setDefinition(VaultRuntimeConfigResolver::class, (new Definition(VaultRuntimeConfigResolver::class))
            ->setAutowired(false)
            ->setArgument('$runtimeConfig', new Reference(VaultRuntimeConfigProvider::class))
            ->setArgument('$yamlBaseline', $runtimeBaseline)
            ->setArgument('$databaseEnabled', (bool) $configStorage['enabled']));

        $container->setDefinition(RuntimeKeyVaultPayloadCryptographer::class, (new Definition(RuntimeKeyVaultPayloadCryptographer::class))
            ->setAutowired(false)
            ->setArgument('$configResolver', new Reference(VaultRuntimeConfigResolver::class)));
        $container->setAlias(VaultPayloadCryptographerInterface::class, RuntimeKeyVaultPayloadCryptographer::class);

        $accessCheckerId = $config['security']['access_checker'] ?? null;
        $this->assertSomeRoleConfigured($config['security']);
        if ($config['security']['allow_unauthenticated']) {
            // Every vault page and secret open to anyone: never in production, whatever the recipe says.
            if ($container->hasParameter('kernel.environment') && $container->getParameter('kernel.environment') === 'prod') {
                throw new LogicException('nowo_vault.security.allow_unauthenticated must not be enabled in the prod environment.');
            }
            $accessCheckerId = 'nowo_vault.access_checker.allow_all';
            $container->setDefinition($accessCheckerId, new Definition(AllowAllVaultAccessChecker::class));
        } elseif (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_vault.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableVaultAccessChecker::class))
                ->setAutowired(true));
        }

        $container->setAlias(VaultAccessCheckerInterface::class, $accessCheckerId);

        $authenticatorId = $browserExtension['authenticator'] ?? null;
        if (!is_string($authenticatorId) || $authenticatorId === '') {
            $authenticatorId  = DefaultVaultBrowserExtensionAuthenticator::class;
            $authenticatorDef = (new Definition(DefaultVaultBrowserExtensionAuthenticator::class))
                ->setAutowired(false)
                ->setArgument('$passwordHasher', new Reference('security.user_password_hasher'));
            $userProviderId = $browserExtension['user_provider'] ?? null;
            if (is_string($userProviderId) && $userProviderId !== '') {
                $authenticatorDef->setArgument('$userProvider', new Reference($userProviderId));
            } else {
                $authenticatorDef->setAutowired(true);
            }
            $userCheckerId = $browserExtension['user_checker'] ?? null;
            if (is_string($userCheckerId) && $userCheckerId !== '') {
                $authenticatorDef->setArgument('$userChecker', new Reference($userCheckerId));
            } else {
                // Fallback when no firewall with security is configured; BrowserExtensionUserCheckerPass
                // replaces it with security.user_checker.<firewall> (the firewall's checker / chain).
                $authenticatorDef
                    ->setArgument('$userChecker', new Reference(UserCheckerInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
                    ->addTag(BrowserExtensionUserCheckerPass::TAG, ['firewall' => $browserExtension['firewall'] ?? null]);
            }
            $container->setDefinition(DefaultVaultBrowserExtensionAuthenticator::class, $authenticatorDef);
        }
        $container->setAlias(VaultBrowserExtensionAuthenticatorInterface::class, $authenticatorId);

        $container->setDefinition(VaultBrowserExtensionResponseFactory::class, (new Definition(VaultBrowserExtensionResponseFactory::class))
            ->setAutowired(false)
            ->setArgument('$allowedOrigins', $browserExtension['cors_allowed_origins'])
            ->setArgument('$kernelEnvironment', '%kernel.environment%'));

    }

    /**
     * Fails at compile time when the default access checker would deny everyone (cross-bundle pattern P1): every role
     * list is empty, allow_unauthenticated is false and no custom access_checker is configured. An empty list never
     * allows everyone (ConfigurableVaultAccessChecker denies it); this only turns a silent lock-out into an error.
     *
     * @param array{access_checker?: string|null, allow_unauthenticated: bool, admin_roles: list<string>, access_roles: list<string>, create_roles: list<string>, list_roles: list<string>, delete_roles: list<string>} $security
     */
    private function assertSomeRoleConfigured(array $security): void
    {
        $checker = $security['access_checker'] ?? null;
        if ($security['allow_unauthenticated'] || (is_string($checker) && $checker !== '')) {
            return;
        }

        foreach (['admin_roles', 'access_roles', 'create_roles', 'list_roles', 'delete_roles'] as $key) {
            if ($security[$key] !== []) {
                return;
            }
        }

        throw new LogicException('nowo_vault.security: every role list (admin_roles, access_roles, create_roles, list_roles, delete_roles) is empty, so nobody could use the vault. Configure at least one role or a security.access_checker service.');
    }

    /**
     * Normalised deprecated css_framework (bootstrap => bootstrap5), null when not set.
     */
    private function cssFramework(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value === 'bootstrap' ? 'bootstrap5' : $value;
    }

    /**
     * Returns the configuration alias (`nowo_vault`).
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }

    /**
     * Prepends the asset package, the Doctrine attribute mapping and FormKit / UiKit defaults (host values win).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'assets' => [
                    'packages' => [
                        'nowo_vault' => [
                            'base_path' => '/bundles/vault',
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'VaultBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }

        $this->prependUiKitDefaults($container);
    }

    /**
     * When FormKit is installed, register the vault profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('vault', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        // REQ-UI-003: no framework utility classes in bundle markup; the host form theme (or this profile, which the
        // host may redefine) styles the fields. `nowo-ui-input` is the UiKit semantic hook.

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'vault' => [
                    'alias'              => 'vault',
                    'translation_domain' => 'NowoVaultBundle',
                    // Help / placeholder convention keys only when translated (no raw `vault_item_form.*` keys).
                    'convention_requires_translation' => true,
                    'defaults'                        => [
                        'attr'     => ['class' => 'nowo-ui-input'],
                        'row_attr' => [],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * When UiKit is installed and the host set the deprecated css_framework (REQ-UI-003: the CSS stack is inherited
     * from nowo_ui_kit), copy it to nowo_ui_kit.css_framework (and the matching icon set) unless the host set them.
     * processConfiguration() is avoided: encryption_key is required and may be unset during an early prepend.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $framework = null;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $cfg) {
            if (is_string($cfg['css_framework'] ?? null)) {
                $framework = $this->cssFramework($cfg['css_framework']);
            }
        }
        if ($framework === null) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            $hostHasCssFramework = $hostHasCssFramework || array_key_exists('css_framework', $cfg);
            $hostHasIconSet      = $hostHasIconSet || array_key_exists('icon_set', $cfg);
        }

        $defaults = [];
        if (!$hostHasCssFramework) {
            $defaults['css_framework'] = $framework;
        }
        if (!$hostHasIconSet) {
            $defaults['icon_set'] = $framework === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }
}
