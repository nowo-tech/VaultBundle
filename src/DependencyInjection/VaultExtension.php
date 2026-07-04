<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection;

use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Integration\PasswordStrengthIntegration;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultFolderRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultGrantRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
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

        $prefix       = rtrim((string) $config['table_prefix'], '_');
        $itemsTable   = $prefix . '_items';
        $foldersTable = $prefix . '_folders';
        $grantsTable  = $prefix . '_grants';
        $database     = $config['database'];
        $emName       = (string) $database['entity_manager'];

        $container->setParameter('nowo_vault.user_class', $config['user_class']);
        $container->setParameter('nowo_vault.items_table', $itemsTable);
        $container->setParameter('nowo_vault.folders_table', $foldersTable);
        $container->setParameter('nowo_vault.grants_table', $grantsTable);
        $container->setParameter('nowo_vault.database', $database);
        $container->setParameter('nowo_vault.route_prefix', $config['route_prefix']);
        $container->setParameter('nowo_vault.dashboard_route', $config['dashboard_route']);
        $container->setParameter('nowo_vault.routes', $config['routes']);
        $container->setParameter('nowo_vault.templates', $config['templates']);
        $container->setParameter('nowo_vault.firewall', $config['firewall']);
        $container->setParameter('nowo_vault.security', $config['security']);
        $container->setParameter('nowo_vault.max_attachment_bytes', $config['max_attachment_bytes']);
        $container->setParameter('nowo_vault.password_field', $config['password_field']);
        $container->setParameter('nowo_vault.password_strength_enabled', PasswordStrengthIntegration::isAvailable());

        $teamResolverId = $config['team_membership_resolver'] ?? null;
        if (!is_string($teamResolverId) || $teamResolverId === '') {
            $teamResolverId = NullVaultTeamMembershipResolver::class;
            $container->setDefinition(NullVaultTeamMembershipResolver::class, new Definition(NullVaultTeamMembershipResolver::class));
        }
        $container->setAlias(VaultTeamMembershipResolverInterface::class, $teamResolverId);

        $container->setDefinition(SodiumVaultPayloadCryptographer::class, (new Definition(SodiumVaultPayloadCryptographer::class))
            ->setArgument('$encryptionKeyBase64', $config['encryption_key']));
        $container->setAlias(VaultPayloadCryptographerInterface::class, SodiumVaultPayloadCryptographer::class);

        $emRef = new Reference(sprintf('doctrine.orm.%s_entity_manager', $emName));

        foreach ([
            DoctrineOrmVaultItemRepository::class   => VaultItemRepositoryInterface::class,
            DoctrineOrmVaultFolderRepository::class => VaultFolderRepositoryInterface::class,
            DoctrineOrmVaultGrantRepository::class  => VaultGrantRepositoryInterface::class,
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
            ->setArgument('$userClass', $config['user_class'])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        $accessCheckerId = $config['security']['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_vault.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableVaultAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$adminRoles', $config['security']['admin_roles'])
                ->setArgument('$accessRoles', $config['security']['access_roles'])
                ->setArgument('$createRoles', $config['security']['create_roles'])
                ->setArgument('$listRoles', $config['security']['list_roles'])
                ->setArgument('$deleteRoles', $config['security']['delete_roles']));
        }

        $container->setAlias(VaultAccessCheckerInterface::class, $accessCheckerId);

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
