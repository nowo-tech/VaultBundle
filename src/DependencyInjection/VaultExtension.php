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
use Nowo\VaultBundle\Controller\VaultExportController;
use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\Command\CheckDowngradeCommand;
use Nowo\VaultBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Doctrine\VaultManagerRegistry;
use Nowo\VaultBundle\EventListener\VaultUnitOfWorkSubscriber;
use Nowo\VaultBundle\Form\VaultItemFormType;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Doctrine\VaultExtensionTokenTable;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Doctrine\VaultUserColumnsListener;
use Nowo\VaultBundle\Dto\VaultPasswordPolicy;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\EventListener\VaultRequestBoundaryListener;
use Nowo\VaultBundle\EventListener\VaultUserGateListener;
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
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigVaultPasswordPolicyResolver;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\RuntimeKeyVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultPasswordPolicyResolverInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthProofValidator;
use Nowo\VaultBundle\Security\VaultRevealRateLimiter;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultSecretAccessRateLimiter;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultHealthReporter;
use Nowo\VaultBundle\Service\VaultHealthReporterInterface;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Ui\VaultPages;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
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
    /** Server-side evaluator of nowo-tech/password-strength-bundle, used by the health report when installed. */
    public const STRENGTH_EVALUATOR = 'Nowo\\PasswordStrengthBundle\\Service\\PasswordStrengthEvaluator';

    /** Route keys registered only with `scopes.enabled` (1.16.0; see VaultRouteLoader::OPTIONAL_ROUTE_KEYS). */
    public const SCOPE_ROUTE_KEYS = ['scope_items', 'scope_item_new', 'scope_folder_create'];

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
        // 1.19.0: the resource scan aliases singly-implemented interfaces; the 1.19 port interfaces are aliased by
        // VaultPortsPass only, so an alias found there is always the application's own.
        foreach (VaultPortsPass::PORTS as $port) {
            $container->removeAlias($port['policy']);
        }

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
        $container->setParameter('nowo_vault.accepted_user_classes', $config['accepted_user_classes']);
        $container->setParameter('nowo_vault.user_mapping', $config['user_mapping']);
        // Deprecated since 1.15 (REQ-UI-003: the pages read the CSS stack from nowo_ui_kit); kept for the deprecated
        // nowo_vault_css_framework Twig global.
        $container->setParameter('nowo_vault.css_framework', $this->cssFramework($config['css_framework']) ?? 'tabler');
        $container->setParameter('nowo_vault.web_ui.enabled', (bool) $config['web_ui']['enabled']);
        $container->setParameter('nowo_vault.web_ui.layout_template', $config['web_ui']['layout_template']);
        $container->setParameter('nowo_vault.web_ui.templates', $config['web_ui']['templates']);
        $container->setParameter('nowo_vault.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);
        $container->setParameter('nowo_vault.security.deny_status', (int) $config['security']['deny_status']);
        $container->setParameter('nowo_vault.security.allow_unlisted_grantees', (bool) $config['security']['allow_unlisted_grantees']);
        $container->setParameter('nowo_vault.items_table', $itemsTable);
        $container->setParameter('nowo_vault.folders_table', $foldersTable);
        $container->setParameter('nowo_vault.grants_table', $grantsTable);
        $container->setParameter('nowo_vault.tags_table', $tagsTable);
        $container->setParameter('nowo_vault.item_tags_table', $itemTagsTable);
        $container->setParameter('nowo_vault.settings_table', $settingsTable);
        $container->setParameter('nowo_vault.extension_tokens_table', $extensionTokensTable);
        $container->setParameter('nowo_vault.web_ui.host_note_template', $config['web_ui']['host_note_template']);
        $container->setParameter('nowo_vault.web_ui.destructive_actions_in_menu', (bool) $config['web_ui']['destructive_actions_in_menu']);
        $container->setParameter('nowo_vault.sharing.enabled', (bool) $config['sharing']['enabled']);
        // 1.18.0 database.conditional_mapping: the entities / tables of disabled features (ConditionalMappingPass).
        $unmappedClasses = [];
        $unmappedTables  = [];
        if ((bool) $database['conditional_mapping']) {
            if (!(bool) $configStorage['enabled']) {
                $unmappedClasses[] = VaultSettings::class;
                $unmappedTables[]  = $settingsTable;
            }
            if (!(bool) $config['browser_extension']['enabled']) {
                $unmappedClasses[] = VaultExtensionToken::class;
                $unmappedTables[]  = $extensionTokensTable;
            }
        }
        $container->setParameter('nowo_vault.unmapped_entity_classes', $unmappedClasses);
        $container->setParameter('nowo_vault.unmapped_tables', $unmappedTables);
        $browserExtension = $config['browser_extension'];
        $container->setParameter('nowo_vault.browser_extension.enabled', (bool) $browserExtension['enabled']);
        $container->setParameter('nowo_vault.browser_extension.token_ttl', (int) $browserExtension['token_ttl']);
        $container->setParameter('nowo_vault.browser_extension.max_login_items', (int) $browserExtension['max_login_items']);
        $container->setParameter('nowo_vault.pagination.per_page', (int) $config['pagination']['per_page']);
        $container->setParameter('nowo_vault.entity_manager', $emName);
        $container->setParameter('nowo_vault.grants.temporary', $config['grants']['temporary']);
        $container->setParameter('nowo_vault.grants.temporary.ui', (bool) $config['grants']['temporary']['ui']);
        $this->registerScopes($config['scopes'], $config['catalogue'], $config['health']['enabled'], $container);
        $this->registerHealth($config['health'], $container);
        $this->registerExport($config['export'], $container);
        $this->registerReveal($config['reveal'], $container);
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
        $secretAccessRateLimit = $config['security']['secret_access_rate_limit'];
        $container->setDefinition(VaultSecretAccessRateLimiter::class, (new Definition(VaultSecretAccessRateLimiter::class))
            ->setAutowired(false)
            ->setArgument('$cache', new Reference((string) $secretAccessRateLimit['cache_pool']))
            ->setArgument('$maxRequests', (int) $secretAccessRateLimit['max_requests'])
            ->setArgument('$windowSeconds', (int) $secretAccessRateLimit['window_seconds'])
            ->setArgument('$enabled', (bool) $secretAccessRateLimit['enabled'])
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)));
        $container->setParameter('nowo_vault.database', $database);
        $container->setParameter('nowo_vault.config_storage', $configStorage);
        $container->setParameter('nowo_vault.config_storage.enabled', (bool) $configStorage['enabled']);
        $container->setParameter('nowo_vault.runtime_config.yaml_baseline', $runtimeBaseline);
        $container->setParameter('nowo_vault.password_strength_enabled', PasswordStrengthIntegration::isAvailable());
        $container->setParameter('nowo_vault.tag_input_enabled', TagInputIntegration::isAvailable());

        $teamResolverId = $config['team_membership_resolver'] ?? null;
        $teamBuiltIn    = !is_string($teamResolverId) || $teamResolverId === '';
        if ($teamBuiltIn) {
            $teamResolverId = NullVaultTeamMembershipResolver::class;
            $container->setDefinition(NullVaultTeamMembershipResolver::class, new Definition(NullVaultTeamMembershipResolver::class));
        }
        $container->setAlias(VaultTeamMembershipResolverInterface::class, $teamResolverId);
        $this->registerPort($container, 'team_membership', $teamResolverId, $teamBuiltIn, 'nowo_vault.team_membership_resolver');
        $this->registerPort($container, 'user_locator', VaultUserLocator::class, true, 'the user locator (VaultUserDirectoryInterface)');

        // 1.19.0 (REQ-ARCH-001 rule 6): the private entity manager (default) or the 1.18 wiring (deprecated opt-out).
        $private = $database['private_entity_manager'] === true;
        if ($database['private_entity_manager'] === null) {
            trigger_deprecation('nowo-tech/vault-bundle', '1.19', 'Not setting "nowo_vault.database.private_entity_manager" is deprecated: it keeps the 1.18 behaviour (the vault flushes the "%s" entity manager on its own writes, including your pending changes) and becomes true in 2.0. Set it to true (recommended, see UPGRADING.md "To 1.19.0").', $emName);
        } elseif (!$private) {
            trigger_deprecation('nowo-tech/vault-bundle', '1.19', '"nowo_vault.database.private_entity_manager: false" is deprecated: the private entity manager becomes the only mode in 2.0.');
        }
        $container->setParameter('nowo_vault.private_entity_manager', $private);
        $emRef       = $private ? new Reference(VaultEntityManager::class) : new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));
        $registryRef = $private ? new Reference(VaultManagerRegistry::class) : new Reference('doctrine');
        if ($private) {
            $this->registerPrivateEntityManager($container, $emName);
        }
        $cachePool = (string) $configStorage['cache_pool'];
        $this->registerUserMapping($config, $emName, $container, $private ? $registryRef : new Reference('doctrine', ContainerInterface::NULL_ON_INVALID_REFERENCE));

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
                ->setArgument('$registry', $registryRef)
                ->setArgument('$managerName', $emName));
            $container->setAlias($interface, $repoClass);
            if ($repoClass !== DoctrineOrmVaultSettingsRepository::class) {
                $container->getDefinition($repoClass)->setArgument('$users', new Reference(VaultUserReferenceResolverInterface::class));
            }
        }

        // 1.18.0 (NC-1): raw access to the token table while its entity is not mapped (conditional_mapping).
        $container->setDefinition(VaultExtensionTokenTable::class, (new Definition(VaultExtensionTokenTable::class))
            ->setAutowired(false)
            ->setArgument('$tableName', $extensionTokensTable));
        $container->getDefinition(DoctrineOrmVaultExtensionTokenRepository::class)
            ->setArgument('$tokenTable', new Reference(VaultExtensionTokenTable::class));

        // Token and grant expiry queries read the host clock when one is registered (REQ-DI-001).
        $container->getDefinition(DoctrineOrmVaultExtensionTokenRepository::class)
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $container->getDefinition(DoctrineOrmVaultGrantRepository::class)
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        $container->setAlias(VaultGrantExpiryRepositoryInterface::class, DoctrineOrmVaultGrantRepository::class);

        $container->setDefinition(VaultMetadataListener::class, (new Definition(VaultMetadataListener::class))
            ->setArgument('$itemsTableName', $itemsTable)
            ->setArgument('$foldersTableName', $foldersTable)
            ->setArgument('$grantsTableName', $grantsTable)
            ->setArgument('$tagsTableName', $tagsTable)
            ->setArgument('$itemTagsTableName', $itemTagsTable)
            ->setArgument('$settingsTableName', $settingsTable)
            ->setArgument('$extensionTokensTableName', $extensionTokensTable)
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$userMapping', $config['user_mapping'])
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
        $accessBuiltIn   = !is_string($accessCheckerId) || $accessCheckerId === '' || (bool) $config['security']['allow_unauthenticated'];
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
        $this->registerPort($container, 'access_checker', $accessCheckerId, $accessBuiltIn, 'nowo_vault.security.access_checker');

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
     * Scope vaults (1.16.0): the host resolver behind the request-scoped cache, and the route keys of disabled
     * features. Fails at compile time when scopes are enabled without a resolver.
     *
     * @param array{enabled: bool, access_resolver: string|null} $scopes
     * @param array{enabled: bool, per_page: int} $catalogue
     */
    private function registerScopes(array $scopes, array $catalogue, bool $health, ContainerBuilder $container): void
    {
        $resolverId = $scopes['access_resolver'] ?? null;
        if ($scopes['enabled'] && (!is_string($resolverId) || $resolverId === '')) {
            throw new LogicException('nowo_vault.scopes.enabled requires nowo_vault.scopes.access_resolver (a service implementing VaultScopeAccessResolverInterface).');
        }

        if (!$scopes['enabled']) {
            $resolverId = NullVaultScopeAccessResolver::class;
            $container->setDefinition(NullVaultScopeAccessResolver::class, new Definition(NullVaultScopeAccessResolver::class));
        }
        $container->setAlias(VaultScopeAccessResolverInterface::class, $resolverId);
        $this->registerPort($container, 'scope_access', (string) $resolverId, !$scopes['enabled'], 'nowo_vault.scopes.access_resolver');
        $container->setDefinition(RequestScopedScopeAccessCache::class, (new Definition(RequestScopedScopeAccessCache::class))
            ->setArgument('$resolver', new Reference(VaultPortsPass::GATE_PREFIX . 'scope_access'))
            ->addTag('kernel.reset', ['method' => 'reset']));
        // 1.17.1 (REQ-RUNTIME-002): kernel.reset alone is not enough with FRANKENPHP_RESET_KERNEL=false; the bundle
        // clears the memo itself at the main request, terminate, console and Messenger worker boundaries.
        $container->setDefinition(VaultRequestBoundaryListener::class, (new Definition(VaultRequestBoundaryListener::class))
            ->setArgument('$memos', new IteratorArgument([new Reference(RequestScopedScopeAccessCache::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)]))
            ->addTag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onKernelRequest', 'priority' => 2048])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onBoundary', 'priority' => -2048])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onBoundary', 'priority' => -2048])
            ->addTag('kernel.event_listener', ['event' => VaultRequestBoundaryListener::WORKER_MESSAGE_HANDLED, 'method' => 'onBoundary', 'priority' => -2048])
            ->addTag('kernel.event_listener', ['event' => VaultRequestBoundaryListener::WORKER_MESSAGE_FAILED, 'method' => 'onBoundary', 'priority' => -2048]));

        $container->setParameter('nowo_vault.scopes.enabled', $scopes['enabled']);
        $enabled = ['item_attachment'];
        if ($scopes['enabled']) {
            $enabled = [...$enabled, ...self::SCOPE_ROUTE_KEYS];
            if ($catalogue['enabled']) {
                $enabled[] = 'catalogue';
            }
            if ($health) {
                $enabled[] = 'health';
            }
        }
        $container->setParameter('nowo_vault.enabled_optional_route_keys', $enabled);
        $container->setParameter('nowo_vault.catalogue.per_page', $catalogue['per_page']);
    }

    /**
     * Health report (1.16.0): the reporter, the password policy resolver and the optional breach checker.
     *
     * @param array{enabled: bool, max_items: int, cache_ttl: int, min_interval_seconds: int, breach_cache_ttl: int, cache_pool: string, breach_checker: string|null, policy_resolver: string|null, policy: array{min_length: int, min_classes: int, max_age_days: int, enforce_on_save: bool}} $health
     */
    private function registerHealth(array $health, ContainerBuilder $container): void
    {
        $policy = $health['policy'];
        $container->setDefinition(ConfigVaultPasswordPolicyResolver::class, (new Definition(ConfigVaultPasswordPolicyResolver::class))
            ->setArgument('$policy', (new Definition(VaultPasswordPolicy::class))->setArguments([$policy['min_length'], $policy['min_classes'], $policy['max_age_days'], $policy['enforce_on_save']])));
        $resolverId = $health['policy_resolver'];
        $container->setAlias(VaultPasswordPolicyResolverInterface::class, is_string($resolverId) && $resolverId !== '' ? $resolverId : ConfigVaultPasswordPolicyResolver::class);

        $breachId = $health['breach_checker'];
        $container->getDefinition(VaultHealthReporter::class)
            ->setArgument('$breachChecker', is_string($breachId) && $breachId !== '' ? new Reference($breachId) : null)
            ->setArgument('$strengthEvaluator', new Reference(self::STRENGTH_EVALUATOR, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$cache', new Reference($health['cache_pool']))
            ->setArgument('$cacheTtl', $health['cache_ttl'])
            ->setArgument('$maxItems', $health['max_items'])
            ->setArgument('$minIntervalSeconds', $health['min_interval_seconds'])
            ->setArgument('$breachCacheTtl', $health['breach_cache_ttl']);
        $container->setAlias(VaultHealthReporterInterface::class, VaultHealthReporter::class);
        $this->registerPort($container, 'health_reporter', VaultHealthReporter::class, true, 'the health reporter (VaultHealthReportProviderInterface)');
    }

    /**
     * Decrypted personal export (1.16.0): the host re-authentication hook (required when enabled), the proof validator,
     * the limits of the export controller and its two optional routes.
     *
     * @param array{enabled: bool, reauth_checker: string|null, reauth_max_age: int, accepted_primary_factors: list<string>, accepted_mfa_factors: list<string>, formats: list<string>, max_items: int, max_bytes: int, include_attachments: bool, cooldown_seconds: int, cache_pool: string, lock_factory: string|null, csv_raw_values: bool} $export
     */
    private function registerExport(array $export, ContainerBuilder $container): void
    {
        $checkerId = $export['reauth_checker'];
        if ($export['enabled']) {
            if (!is_string($checkerId) || $checkerId === '') {
                throw new LogicException('nowo_vault.export.enabled requires nowo_vault.export.reauth_checker (a service implementing VaultReauthenticationCheckerInterface); there is no default.');
            }
            $container->setAlias(VaultReauthenticationCheckerInterface::class, $checkerId);
            $this->registerPort($container, 'reauthentication', $checkerId, false, 'nowo_vault.export.reauth_checker');
            /** @var list<string> $enabled */
            $enabled = $container->getParameter('nowo_vault.enabled_optional_route_keys');
            $container->setParameter('nowo_vault.enabled_optional_route_keys', [...$enabled, 'personal_export', 'personal_export_download']);
        }

        // Checked by ExportLockFactoryPass: an explicit, non-default id must resolve (SEC-004 FQ-I13).
        $container->setParameter('nowo_vault.export.lock_factory', $export['enabled'] ? $export['lock_factory'] : null);
        $pool = new Reference($export['cache_pool']);
        $container->setDefinition(VaultReauthProofValidator::class, (new Definition(VaultReauthProofValidator::class))
            ->setAutowired(false)
            ->setArgument('$maxAge', $export['reauth_max_age'])
            ->setArgument('$acceptedPrimaryFactors', $export['accepted_primary_factors'])
            ->setArgument('$acceptedMfaFactors', $export['accepted_mfa_factors'])
            ->setArgument('$consumedProofs', $pool)
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)));
        $container->getDefinition(VaultExportController::class)
            ->setArgument('$cooldownPool', $pool)
            ->setArgument('$reauthChecker', new Reference(VaultPortsPass::GATE_PREFIX . 'reauthentication', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$formats', $export['formats'])
            ->setArgument('$maxItems', $export['max_items'])
            ->setArgument('$maxBytes', $export['max_bytes'])
            ->setArgument('$cooldownSeconds', $export['cooldown_seconds'])
            ->setArgument('$includeAttachments', $export['include_attachments'])
            ->setArgument('$lockFactory', is_string($export['lock_factory']) && $export['lock_factory'] !== '' ? new Reference($export['lock_factory'], ContainerInterface::NULL_ON_INVALID_REFERENCE) : null)
            ->setArgument('$csvRawValues', $export['csv_raw_values']);
        $container->getDefinition(VaultPersonalExportStreamer::class)->setArgument('$csvRawValues', $export['csv_raw_values']);
    }

    /**
     * Reveal additions (1.16.0): the per-user reveal limiter, the revealable fields and the auto-hide delay.
     *
     * @param array{auto_hide_seconds: int, revealable_fields: array<string, list<string>>, rate_limit: array{enabled: bool, per_user_per_minute: int, per_user_per_hour: int, per_user_item_field_per_minute: int, cache_pool: string}} $reveal
     */
    private function registerReveal(array $reveal, ContainerBuilder $container): void
    {
        $limit = $reveal['rate_limit'];
        $container->setDefinition(VaultRevealRateLimiter::class, (new Definition(VaultRevealRateLimiter::class))
            ->setAutowired(false)
            ->setArgument('$cache', new Reference($limit['cache_pool']))
            ->setArgument('$perUserPerMinute', $limit['per_user_per_minute'])
            ->setArgument('$perUserPerHour', $limit['per_user_per_hour'])
            ->setArgument('$perUserItemFieldPerMinute', $limit['per_user_item_field_per_minute'])
            ->setArgument('$enabled', $limit['enabled'])
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)));
        $container->setParameter('nowo_vault.reveal.auto_hide_seconds', $reveal['auto_hide_seconds']);
        $container->setParameter('nowo_vault.reveal.revealable_fields', $reveal['revealable_fields'] === [] ? null : $reveal['revealable_fields']);
    }

    /**
     * The vault's private entity manager (1.19.0, REQ-ARCH-001 rule 6): the repositories and the services that read or
     * write vault rows ({@see VaultManagerRegistry}) use {@see VaultEntityManager}, created on the connection,
     * configuration and event manager of `database.entity_manager`, so a vault write never flushes, clears or resets an
     * application manager. The manager is dropped at every unit-of-work boundary ({@see VaultUnitOfWorkSubscriber}) and
     * at `kernel.reset`; the rollback middleware is attached to its connection by {@see PrivateEntityManagerPass}
     * (doctrine/dbal 4 only).
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, string $emName): void
    {
        $container->register(VaultEntityManager::class, VaultEntityManager::class)
            ->setArguments([new Reference('doctrine'), $emName, new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $container->register(VaultManagerRegistry::class, VaultManagerRegistry::class)
            ->setArguments([new Reference('doctrine'), new Reference(VaultEntityManager::class), $emName]);

        foreach ([VaultPersonalDataEraser::class, VaultPersonalDataExporter::class, VaultPersonalExportStreamer::class, VaultCatalogue::class, CheckDowngradeCommand::class] as $service) {
            $container->getDefinition($service)->setArgument('$registry', new Reference(VaultManagerRegistry::class));
        }
        // The folder choices come from the private manager: the form reads their ids through it.
        $container->getDefinition(VaultItemFormType::class)->setArgument('$folderManager', new Reference(VaultEntityManager::class));

        if (PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $container->register(PrivateEntityManagerMiddleware::class, PrivateEntityManagerMiddleware::class)
                ->setArguments([new Reference(VaultEntityManager::class)])
                ->addTag('doctrine.middleware');
        }

        $container->getDefinition(VaultUnitOfWorkSubscriber::class)
            ->setArgument('$resettables', new IteratorArgument([
                new Reference(VaultEntityManager::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE),
            ]));
    }

    /**
     * One host port with a 1.19 framework-free twin (REQ-ARCH-001 rule 3): the service the configuration names (or the
     * built-in) and the internal gate the vault services use; {@see VaultPortsPass} picks the interface(s) to ask.
     */
    private function registerPort(ContainerBuilder $container, string $name, string $serviceId, bool $builtIn, string $option): void
    {
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $name . '.service', $serviceId);
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $name . '.built_in', $builtIn);
        $container->setParameter(VaultPortsPass::PARAMETER_PREFIX . $name . '.option', $option);
        $container->setDefinition(VaultPortsPass::GATE_PREFIX . $name, (new Definition(VaultPortsPass::PORTS[$name]['gate']))
            ->setAutowired(false)
            ->setPublic(false)
            ->setArguments([VaultPortMode::LEGACY, new Reference(VaultPortsPass::PORTS[$name]['legacy']), null]));
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
     * Host user mapping (1.17.0): accepted security-user classes, the user reference resolver (relation or id
     * mapping), the listener normalising user columns of new rows and the gate refusing other users on the vault pages.
     *
     * @param array<string, mixed> $config
     */
    private function registerUserMapping(array $config, string $emName, ContainerBuilder $container, Reference $registry): void
    {
        $container->setDefinition(VaultUserClassPolicy::class, (new Definition(VaultUserClassPolicy::class))
            ->setAutowired(false)
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$acceptedUserClasses', $config['accepted_user_classes'])
            ->setArgument('$userMapping', $config['user_mapping']));

        $container->setDefinition(DoctrineVaultUserReferenceResolver::class, (new Definition(DoctrineVaultUserReferenceResolver::class))
            ->setAutowired(false)
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$classes', new Reference(VaultUserClassPolicy::class))
            ->setArgument('$userMapping', $config['user_mapping'])
            ->setArgument('$registry', $registry)
            ->setArgument('$managerName', $emName));
        $container->setAlias(VaultUserReferenceResolverInterface::class, DoctrineVaultUserReferenceResolver::class);

        $container->setDefinition(VaultUserColumnsListener::class, (new Definition(VaultUserColumnsListener::class))
            ->setAutowired(false)
            ->setArgument('$users', new Reference(VaultUserReferenceResolverInterface::class))
            ->addTag('doctrine.event_listener', ['event' => 'prePersist']));

        $container->setDefinition(VaultUserGateListener::class, (new Definition(VaultUserGateListener::class))
            ->setAutowired(false)
            ->setArgument('$users', new Reference(VaultUserReferenceResolverInterface::class))
            ->setArgument('$tokenStorage', new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onKernelController']));
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
        if ($container->hasExtension('nowo_ui_kit') && $this->destructiveActionsInMenu($container)) {
            // 1.18.0 web_ui.destructive_actions_in_menu: the vault row_actions partial (delegates to the host / kit
            // partial; draws the actions marked with VaultPages::ROW_MENU_ATTRIBUTE in a kebab menu). A host value for
            // this bundle partial wins.
            $container->prependExtensionConfig('nowo_ui_kit', [
                'theme' => ['bundles' => [VaultPages::BUNDLE => ['partials' => ['row_actions' => VaultPages::ROW_ACTIONS_PARTIAL]]]],
            ]);
        }
    }

    /**
     * Whether the raw nowo_vault config turns on web_ui.destructive_actions_in_menu (read before processing, like
     * {@see self::prependUiKitDefaults()}; the last value wins).
     */
    private function destructiveActionsInMenu(ContainerBuilder $container): bool
    {
        $enabled = false;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $cfg) {
            if (is_array($cfg['web_ui'] ?? null) && array_key_exists('destructive_actions_in_menu', $cfg['web_ui'])) {
                $enabled = (bool) $cfg['web_ui']['destructive_actions_in_menu'];
            }
        }

        return $enabled;
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
