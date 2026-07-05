<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection;

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

use function is_string;
use function rtrim;
use function sprintf;

final class VaultExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

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
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
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

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    'nowo_vault' => [
                        'base_path' => '/bundles/vault',
                    ],
                ],
            ],
        ]);

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
    }
}
