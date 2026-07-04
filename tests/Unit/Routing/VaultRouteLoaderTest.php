<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Routing;

use Nowo\VaultBundle\Routing\VaultRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VaultRouteLoaderTest extends TestCase
{
    public function testLoadsVaultRoutes(): void
    {
        $loader = new VaultRouteLoader([
            'index'               => ['path' => '/tools/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/tools/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
        ], '');

        $collection = $loader->load('.', 'nowo_vault');

        self::assertTrue($collection->get('nowo_vault_index') instanceof \Symfony\Component\Routing\Route);
        self::assertTrue($collection->get('nowo_vault_item_share') instanceof \Symfony\Component\Routing\Route);
        self::assertTrue($loader->supports('.', 'nowo_vault'));
        self::assertFalse($loader->supports('.', 'other'));
    }

    public function testAppliesRoutePrefix(): void
    {
        $loader = new VaultRouteLoader([
            'index'               => ['path' => '/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
        ], '/admin');

        $route = $loader->load('.', 'nowo_vault')->get('nowo_vault_index');
        self::assertNotNull($route);
        self::assertSame('/admin/vault', $route->getPath());
    }

    public function testCannotLoadTwice(): void
    {
        $routes = [
            'index'               => ['path' => '/tools/vault', 'name' => 'nowo_vault_index'],
            'items'               => ['path' => '/tools/vault/items', 'name' => 'nowo_vault_items'],
            'shared'              => ['path' => '/shared', 'name' => 'nowo_vault_shared'],
            'trash'               => ['path' => '/trash', 'name' => 'nowo_vault_trash'],
            'item_new'            => ['path' => '/items/new/{type}', 'name' => 'nowo_vault_item_new'],
            'item_edit'           => ['path' => '/items/{id}/edit', 'name' => 'nowo_vault_item_edit'],
            'item_trash'          => ['path' => '/items/{id}/trash', 'name' => 'nowo_vault_item_trash'],
            'item_restore'        => ['path' => '/items/{id}/restore', 'name' => 'nowo_vault_item_restore'],
            'item_purge'          => ['path' => '/items/{id}/purge', 'name' => 'nowo_vault_item_purge'],
            'item_share'          => ['path' => '/items/{id}/share', 'name' => 'nowo_vault_item_share'],
            'item_grant_revoke'   => ['path' => '/items/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_item_grant_revoke'],
            'folder_create'       => ['path' => '/folders', 'name' => 'nowo_vault_folder_create'],
            'folder_trash'        => ['path' => '/folders/{id}/trash', 'name' => 'nowo_vault_folder_trash'],
            'folder_share'        => ['path' => '/folders/{id}/share', 'name' => 'nowo_vault_folder_share'],
            'folder_grant_revoke' => ['path' => '/folders/{id}/grants/{grantId}/revoke', 'name' => 'nowo_vault_folder_grant_revoke'],
            'password_generate'   => ['path' => '/password/generate', 'name' => 'nowo_vault_password_generate'],
        ];
        $loader = new VaultRouteLoader($routes, '');
        $loader->load('.', 'nowo_vault');

        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_vault');
    }
}
