<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Validation tree for runtime configuration (YAML baseline + DB overrides).
 */
final class RuntimeConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('nowo_vault_runtime');
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->scalarNode('encryption_key')
                    ->defaultNull()
                ->end()
                ->integerNode('max_attachment_bytes')
                    ->defaultValue(512_000)
                    ->min(0)
                ->end()
                ->arrayNode('password_field')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('level')->defaultValue('medium')->end()
                        ->enumNode('generator_mode')
                            ->values(['off', 'input', 'modal'])
                            ->defaultValue('input')
                        ->end()
                        ->booleanNode('use_password_toggle')->defaultTrue()->end()
                    ->end()
                ->end()
                ->scalarNode('route_prefix')->defaultValue('')->end()
                ->scalarNode('dashboard_route')->defaultNull()->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('admin_roles')->scalarPrototype()->end()->defaultValue(['ROLE_ADMIN'])->end()
                        ->arrayNode('access_roles')->scalarPrototype()->end()->defaultValue(['ROLE_USER'])->end()
                        ->arrayNode('create_roles')->scalarPrototype()->end()->defaultValue(['ROLE_USER'])->end()
                        ->arrayNode('list_roles')->scalarPrototype()->end()->defaultValue(['ROLE_USER'])->end()
                        ->arrayNode('delete_roles')->scalarPrototype()->end()->defaultValue(['ROLE_USER'])->end()
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('index')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_index')->end()
                        ->end()->end()
                        ->arrayNode('items')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_items')->end()
                        ->end()->end()
                        ->arrayNode('shared')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/shared')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_shared')->end()
                        ->end()->end()
                        ->arrayNode('trash')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/trash')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_trash')->end()
                        ->end()->end()
                        ->arrayNode('item_new')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/new/{type}')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_new')->end()
                        ->end()->end()
                        ->arrayNode('item_edit')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}/edit')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_edit')->end()
                        ->end()->end()
                        ->arrayNode('item_view')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_view')->end()
                        ->end()->end()
                        ->arrayNode('item_trash')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}/trash')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_trash')->end()
                        ->end()->end()
                        ->arrayNode('item_restore')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}/restore')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_restore')->end()
                        ->end()->end()
                        ->arrayNode('item_purge')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}/purge')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_purge')->end()
                        ->end()->end()
                        ->arrayNode('folder_create')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/folders')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_folder_create')->end()
                        ->end()->end()
                        ->arrayNode('folder_trash')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/folders/{id}/trash')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_folder_trash')->end()
                        ->end()->end()
                        ->arrayNode('tag_delete')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/tags/{id}/delete')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_tag_delete')->end()
                        ->end()->end()
                        ->arrayNode('item_share')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}/share')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_share')->end()
                        ->end()->end()
                        ->arrayNode('item_grant_revoke')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/items/{id}/grants/{grantId}/revoke')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_item_grant_revoke')->end()
                        ->end()->end()
                        ->arrayNode('folder_share')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/folders/{id}/share')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_folder_share')->end()
                        ->end()->end()
                        ->arrayNode('folder_grant_revoke')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/folders/{id}/grants/{grantId}/revoke')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_folder_grant_revoke')->end()
                        ->end()->end()
                        ->arrayNode('password_generate')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/password/generate')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_password_generate')->end()
                        ->end()->end()
                        ->arrayNode('runtime_config')->addDefaultsIfNotSet()->children()
                            ->scalarNode('path')->defaultValue('/tools/vault/runtime-config')->end()
                            ->scalarNode('name')->defaultValue('nowo_vault_runtime_config')->end()
                        ->end()->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('layout')->defaultValue('@NowoVaultBundle/layout.html.twig')->end()
                        ->scalarNode('home')->defaultValue('@NowoVaultBundle/vault/home.html.twig')->end()
                        ->scalarNode('items')->defaultValue('@NowoVaultBundle/vault/items.html.twig')->end()
                        ->scalarNode('index')->defaultValue('@NowoVaultBundle/vault/home.html.twig')->end()
                        ->scalarNode('item_form')->defaultValue('@NowoVaultBundle/vault/item_form.html.twig')->end()
                        ->scalarNode('trash')->defaultValue('@NowoVaultBundle/vault/trash.html.twig')->end()
                        ->scalarNode('shared')->defaultValue('@NowoVaultBundle/vault/shared.html.twig')->end()
                        ->scalarNode('share')->defaultValue('@NowoVaultBundle/vault/share.html.twig')->end()
                        ->scalarNode('runtime_config')->defaultValue('@NowoVaultBundle/vault/runtime_config.html.twig')->end()
                    ->end()
                ->end()
                ->scalarNode('firewall')->defaultValue('main')->end()
            ->end();

        return $treeBuilder;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function extractBaseline(array $config): array
    {
        return [
            'encryption_key'       => $config['encryption_key'],
            'max_attachment_bytes' => $config['max_attachment_bytes'],
            'password_field'       => $config['password_field'],
            'route_prefix'         => $config['route_prefix'],
            'dashboard_route'      => $config['dashboard_route'],
            'security'             => [
                'admin_roles'  => $config['security']['admin_roles'],
                'access_roles' => $config['security']['access_roles'],
                'create_roles' => $config['security']['create_roles'],
                'list_roles'   => $config['security']['list_roles'],
                'delete_roles' => $config['security']['delete_roles'],
            ],
            'routes'    => $config['routes'],
            'templates' => $config['templates'],
            'firewall'  => $config['firewall'],
        ];
    }
}
