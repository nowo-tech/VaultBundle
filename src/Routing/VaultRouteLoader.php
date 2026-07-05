<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Routing;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Controller\VaultBrowserExtensionController;
use Nowo\VaultBundle\Controller\VaultManageController;
use Nowo\VaultBundle\Controller\VaultRuntimeConfigController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class VaultRouteLoader extends Loader
{
    private bool $loaded = false;

    public function __construct(
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly bool $configStorageEnabled,
        private readonly bool $browserExtensionEnabled,
        /** @var array<string, array{path: string, name: string}> */
        private readonly array $browserExtensionRoutes,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Vault routes already loaded.');
        }

        $this->loaded = true;
        $config       = $this->runtimeConfig->get();
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes      = $config['routes'];
        $routePrefix = (string) $config['route_prefix'];
        $collection  = new RouteCollection();
        $controller  = VaultManageController::class;

        /** @var array<string, array{0: string, 1: list<string>, 2: array<string, string>}> $map */
        $map = [
            'index'               => ['home', ['GET'], []],
            'items'               => ['items', ['GET'], []],
            'shared'              => ['shared', ['GET'], []],
            'trash'               => ['trash', ['GET'], []],
            'item_new'            => ['newItem', ['GET', 'POST'], ['type' => '[a-z_]+']],
            'item_edit'           => ['editItem', ['GET', 'POST'], ['id' => '[0-9a-f-]{36}']],
            'item_view'           => ['viewItem', ['GET'], ['id' => '[0-9a-f-]{36}']],
            'item_trash'          => ['trashItem', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'item_restore'        => ['restoreItem', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'item_purge'          => ['purgeItem', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'item_share'          => ['shareItem', ['GET', 'POST'], ['id' => '[0-9a-f-]{36}']],
            'item_grant_revoke'   => ['revokeItemGrant', ['POST'], ['id' => '[0-9a-f-]{36}', 'grantId' => '[0-9a-f-]{36}']],
            'folder_create'       => ['createFolder', ['POST'], []],
            'folder_trash'        => ['trashFolder', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'tag_delete'          => ['deleteTag', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'folder_share'        => ['shareFolder', ['GET', 'POST'], ['id' => '[0-9a-f-]{36}']],
            'folder_grant_revoke' => ['revokeFolderGrant', ['POST'], ['id' => '[0-9a-f-]{36}', 'grantId' => '[0-9a-f-]{36}']],
            'password_generate'   => ['generatePassword', ['POST'], []],
        ];

        foreach ($map as $key => [$action, $methods, $requirements]) {
            $collection->add(
                $routes[$key]['name'],
                $this->createRoute(
                    $routes[$key]['path'],
                    ['_controller' => $controller . '::' . $action],
                    $methods,
                    $routePrefix,
                    $requirements,
                ),
            );
        }

        if ($this->configStorageEnabled && isset($routes['runtime_config'])) {
            $collection->add(
                $routes['runtime_config']['name'],
                $this->createRoute(
                    $routes['runtime_config']['path'],
                    ['_controller' => VaultRuntimeConfigController::class . '::__invoke'],
                    ['GET', 'POST'],
                    $routePrefix,
                ),
            );
        }

        if ($this->browserExtensionEnabled) {
            $extensionController = VaultBrowserExtensionController::class;
            /** @var array<string, array{0: string, 1: list<string>}> $extensionMap */
            $extensionMap = [
                'login'  => ['login', ['POST', 'OPTIONS']],
                'logins' => ['logins', ['GET', 'OPTIONS']],
                'logout' => ['logout', ['POST', 'OPTIONS']],
                'me'     => ['me', ['GET', 'OPTIONS']],
            ];

            foreach ($extensionMap as $key => [$action, $methods]) {
                if (!isset($this->browserExtensionRoutes[$key])) {
                    continue;
                }

                $collection->add(
                    $this->browserExtensionRoutes[$key]['name'],
                    $this->createRoute(
                        $this->browserExtensionRoutes[$key]['path'],
                        ['_controller' => $extensionController . '::' . $action],
                        $methods,
                        $routePrefix,
                    ),
                );
            }
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_vault';
    }

    /**
     * @param list<string> $methods
     * @param array<string, string> $requirements
     * @param array<string, mixed> $defaults
     */
    private function createRoute(string $path, array $defaults, array $methods, string $routePrefix, array $requirements = []): Route
    {
        return new Route($routePrefix . $path, $defaults, $requirements, [], '', [], $methods);
    }
}
