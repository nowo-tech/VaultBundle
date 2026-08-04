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
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;

final class VaultExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

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
        $container->setParameter('nowo_vault.css_framework', $config['css_framework']);
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
        $container->setParameter('nowo_vault.browser_extension.routes', $browserExtension['routes']);
        $container->setParameter('nowo_vault.browser_extension.cors_allowed_origins', $browserExtension['cors_allowed_origins']);
        $loginRateLimit = $browserExtension['login_rate_limit'];
        $container->setDefinition(VaultBrowserExtensionLoginRateLimiter::class, (new Definition(VaultBrowserExtensionLoginRateLimiter::class))
            ->setAutowired(false)
            ->setArgument('$cache', new Reference((string) $loginRateLimit['cache_pool']))
            ->setArgument('$maxAttempts', (int) $loginRateLimit['max_attempts'])
            ->setArgument('$intervalSeconds', (int) $loginRateLimit['interval_seconds'])
            ->setArgument('$enabled', (bool) $loginRateLimit['enabled']));
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
                ->setArgument('$entityManager', $emRef));
            $container->setAlias($interface, $repoClass);
        }

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
            ->setArgument('$configProvider', new Reference(VaultRuntimeConfigProvider::class)));

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
        if ($config['security']['allow_unauthenticated']) {
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
            $container->setDefinition(DefaultVaultBrowserExtensionAuthenticator::class, $authenticatorDef);
        }
        $container->setAlias(VaultBrowserExtensionAuthenticatorInterface::class, $authenticatorId);

        $container->setDefinition(VaultBrowserExtensionResponseFactory::class, (new Definition(VaultBrowserExtensionResponseFactory::class))
            ->setAutowired(false)
            ->setArgument('$allowedOrigins', $browserExtension['cors_allowed_origins'])
            ->setArgument('$kernelEnvironment', '%kernel.environment%'));

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

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
     * When UiKit is installed, seed nowo_ui_kit.css_framework / icon_set from
     * root css_framework so kit macros resolve the same stack.
     * Does not override keys the host already set under nowo_ui_kit.
     */

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

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'vault' => [
                    'alias'              => 'vault',
                    'translation_domain' => 'NowoVaultBundle',
                    'defaults'           => [
                        'attr'     => ['class' => 'nowo-ui-input form-control'],
                        'row_attr' => ['class' => 'mb-2'],
                    ],
                    'field_types' => [
                        'checkbox' => [
                            'attr'     => ['class' => 'form-check-input'],
                            'row_attr' => ['class' => 'form-check mb-2'],
                        ],
                        'choice' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'entity' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'file' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                        'textarea' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        if ($hostHasCssFramework && $hostHasIconSet) {
            return;
        }

        // Avoid processConfiguration(): encryption_key is required and may be unset during early prepend.
        $fw = 'tabler';
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $cfg) {
            if (is_array($cfg) && isset($cfg['css_framework']) && is_string($cfg['css_framework'])) {
                $fw = $cfg['css_framework'];
            }
        }
        if ($fw === 'bootstrap') {
            $fw = 'bootstrap5';
        }

        $defaults = [];
        if (!$hostHasCssFramework) {
            $defaults['css_framework'] = $fw;
        }
        if (!$hostHasIconSet) {
            $defaults['icon_set'] = $fw === 'tabler' ? 'tabler-icons' : 'bootstrap-icons';
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }
}
