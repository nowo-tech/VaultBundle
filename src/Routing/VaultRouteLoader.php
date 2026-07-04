<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Routing;

use Nowo\VaultBundle\Controller\VaultManageController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class VaultRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly array $routes,
        private readonly string $routePrefix,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Vault routes already loaded.');
        }

        $this->loaded = true;
        $collection   = new RouteCollection();
        $controller   = VaultManageController::class;

        /** @var array<string, array{0: string, 1: list<string>, 2: array<string, string>}> $map */
        $map = [
            'index'               => ['home', ['GET'], []],
            'items'               => ['items', ['GET'], []],
            'shared'              => ['shared', ['GET'], []],
            'trash'               => ['trash', ['GET'], []],
            'item_new'            => ['newItem', ['GET', 'POST'], ['type' => '[a-z_]+']],
            'item_edit'           => ['editItem', ['GET', 'POST'], ['id' => '[0-9a-f-]{36}']],
            'item_trash'          => ['trashItem', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'item_restore'        => ['restoreItem', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'item_purge'          => ['purgeItem', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'item_share'          => ['shareItem', ['GET', 'POST'], ['id' => '[0-9a-f-]{36}']],
            'item_grant_revoke'   => ['revokeItemGrant', ['POST'], ['id' => '[0-9a-f-]{36}', 'grantId' => '[0-9a-f-]{36}']],
            'folder_create'       => ['createFolder', ['POST'], []],
            'folder_trash'        => ['trashFolder', ['POST'], ['id' => '[0-9a-f-]{36}']],
            'folder_share'        => ['shareFolder', ['GET', 'POST'], ['id' => '[0-9a-f-]{36}']],
            'folder_grant_revoke' => ['revokeFolderGrant', ['POST'], ['id' => '[0-9a-f-]{36}', 'grantId' => '[0-9a-f-]{36}']],
            'password_generate'   => ['generatePassword', ['POST'], []],
        ];

        foreach ($map as $key => [$action, $methods, $requirements]) {
            $collection->add(
                $this->routes[$key]['name'],
                $this->createRoute(
                    $this->routes[$key]['path'],
                    ['_controller' => $controller . '::' . $action],
                    $methods,
                    $requirements,
                ),
            );
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
    private function createRoute(string $path, array $defaults, array $methods, array $requirements = []): Route
    {
        return new Route($this->routePrefix . $path, $defaults, $requirements, [], '', [], $methods);
    }
}
