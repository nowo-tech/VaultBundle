<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Routing;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Route;

final class VaultRouteLoaderTest extends TestCase
{
    public function testLoadsVaultRoutes(): void
    {
        $loader = new VaultRouteLoader($this->createProvider([
            'index'               => ['path' => '/tools/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/tools/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_view'           => ['path' => '/items/{id}', 'name' => 'nowo_vault_item_view'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'tag_delete'          => ['path' => '/tags/{id}/delete', 'name' => 'nowo_vault_tag_delete'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
            'runtime_config'      => ['path' => '/runtime-config', 'name' => 'nowo_vault_runtime_config'],
        ]), false, false, []);

        $collection = $loader->load('.', 'nowo_vault');

        self::assertTrue($collection->get('nowo_vault_index') instanceof Route);
        self::assertTrue($collection->get('nowo_vault_item_share') instanceof Route);
        self::assertNull($collection->get('nowo_vault_runtime_config'));
        self::assertTrue($loader->supports('.', 'nowo_vault'));
        self::assertFalse($loader->supports('.', 'other'));
    }

    public function testLoadsRuntimeConfigRouteWhenStorageEnabled(): void
    {
        $loader = new VaultRouteLoader($this->createProvider([
            'index'               => ['path' => '/tools/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/tools/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_view'           => ['path' => '/items/{id}', 'name' => 'nowo_vault_item_view'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'tag_delete'          => ['path' => '/tags/{id}/delete', 'name' => 'nowo_vault_tag_delete'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
            'runtime_config'      => ['path' => '/runtime-config', 'name' => 'nowo_vault_runtime_config'],
        ]), true, false, []);

        $route = $loader->load('.', 'nowo_vault')->get('nowo_vault_runtime_config');

        self::assertNotNull($route);
        self::assertSame('/runtime-config', $route->getPath());
        self::assertSame(['GET', 'POST'], $route->getMethods());
    }

    public function testAppliesRoutePrefix(): void
    {
        $loader = new VaultRouteLoader($this->createProvider([
            'index'               => ['path' => '/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_view'           => ['path' => '/items/{id}', 'name' => 'nowo_vault_item_view'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'tag_delete'          => ['path' => '/tags/{id}/delete', 'name' => 'nowo_vault_tag_delete'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
        ], '/admin'), false, false, []);

        $route = $loader->load('.', 'nowo_vault')->get('nowo_vault_index');
        self::assertNotNull($route);
        self::assertSame('/admin/vault', $route->getPath());
    }

    public function testLoadsBrowserExtensionRoutesWhenEnabled(): void
    {
        $extensionRoutes = [
            'login'  => ['path' => '/api/vault/extension/login', 'name' => 'nowo_vault_extension_login'],
            'logins' => ['path' => '/api/vault/extension/logins', 'name' => 'nowo_vault_extension_logins'],
            'logout' => ['path' => '/api/vault/extension/logout', 'name' => 'nowo_vault_extension_logout'],
            'me'     => ['path' => '/api/vault/extension/me', 'name' => 'nowo_vault_extension_me'],
        ];

        $loader = new VaultRouteLoader($this->createProvider([
            'index'               => ['path' => '/tools/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/tools/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_view'           => ['path' => '/items/{id}', 'name' => 'nowo_vault_item_view'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'tag_delete'          => ['path' => '/tags/{id}/delete', 'name' => 'nowo_vault_tag_delete'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
        ]), false, true, $extensionRoutes);

        $route = $loader->load('.', 'nowo_vault')->get('nowo_vault_extension_logins');
        self::assertNotNull($route);
        self::assertSame(['GET', 'OPTIONS'], $route->getMethods());
    }

    public function testCannotLoadTwice(): void
    {
        $loader = new VaultRouteLoader($this->createProvider([
            'index'               => ['path' => '/tools/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/tools/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_view'           => ['path' => '/items/{id}', 'name' => 'nowo_vault_item_view'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'tag_delete'          => ['path' => '/tags/{id}/delete', 'name' => 'nowo_vault_tag_delete'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
            'runtime_config'      => ['path' => '/runtime-config', 'name' => 'nowo_vault_runtime_config'],
        ]), false, false, []);
        $loader->load('.', 'nowo_vault');

        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_vault');
    }

    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    private function createProvider(array $routes, string $routePrefix = ''): VaultRuntimeConfigProvider
    {
        return new VaultRuntimeConfigProvider(
            VaultRuntimeConfigFactory::baseline([
                'routes'       => $routes,
                'route_prefix' => $routePrefix,
            ]),
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            new ArrayAdapter(),
        );
    }
}
