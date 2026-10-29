<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Routing;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Controller\VaultBrowserExtensionController;
use Nowo\VaultBundle\Controller\VaultManageController;
use Nowo\VaultBundle\Controller\VaultRuntimeConfigController;
use Nowo\VaultBundle\DependencyInjection\Configuration;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;

/**
 * Locks the attribute-based routes (REQ-SF-004) to the collection the pre-attribute loader produced.
 */
final class VaultRouteLoaderAttributesTest extends TestCase
{
    private const UUID = '[0-9a-f-]{36}';

    /**
     * Route key => [action, methods, requirements] exactly as hard-coded by the loader before v1.14.
     *
     * @var array<string, array{0: string, 1: list<string>, 2: array<string, string>}>
     */
    private const LEGACY_MANAGE_MAP = [
        'index'               => ['home', ['GET'], []],
        'items'               => ['items', ['GET'], []],
        'shared'              => ['shared', ['GET'], []],
        'trash'               => ['trash', ['GET'], []],
        'item_new'            => ['newItem', ['GET', 'POST'], ['type' => '[a-z_]+']],
        'item_edit'           => ['editItem', ['GET', 'POST'], ['id' => self::UUID]],
        'item_view'           => ['viewItem', ['GET'], ['id' => self::UUID]],
        'item_trash'          => ['trashItem', ['POST'], ['id' => self::UUID]],
        'item_restore'        => ['restoreItem', ['POST'], ['id' => self::UUID]],
        'item_purge'          => ['purgeItem', ['POST'], ['id' => self::UUID]],
        'item_share'          => ['shareItem', ['GET', 'POST'], ['id' => self::UUID]],
        'item_grant_revoke'   => ['revokeItemGrant', ['POST'], ['id' => self::UUID, 'grantId' => self::UUID]],
        'folder_create'       => ['createFolder', ['POST'], []],
        'folder_trash'        => ['trashFolder', ['POST'], ['id' => self::UUID]],
        'tag_delete'          => ['deleteTag', ['POST'], ['id' => self::UUID]],
        'folder_share'        => ['shareFolder', ['GET', 'POST'], ['id' => self::UUID]],
        'folder_grant_revoke' => ['revokeFolderGrant', ['POST'], ['id' => self::UUID, 'grantId' => self::UUID]],
        'password_generate'   => ['generatePassword', ['POST'], []],
    ];

    /** @var array<string, array{0: string, 1: list<string>}> */
    private const LEGACY_EXTENSION_MAP = [
        'login'  => ['login', ['POST', 'OPTIONS']],
        'logins' => ['logins', ['GET', 'OPTIONS']],
        'logout' => ['logout', ['POST', 'OPTIONS']],
        'me'     => ['me', ['GET', 'OPTIONS']],
    ];

    public function testCollectionMatchesLegacyLoaderForDefaultConfiguration(): void
    {
        $config = $this->defaultConfig();
        $loader = new VaultRouteLoader($this->createProvider($config['routes'], '/admin'), true, true, $config['browser_extension']['routes']);

        $collection = $loader->load('.', 'nowo_vault');
        $expected   = [];
        foreach (self::LEGACY_MANAGE_MAP as $key => [$action, $methods, $requirements]) {
            $expected[$config['routes'][$key]['name']] = ['/admin' . $config['routes'][$key]['path'], VaultManageController::class . '::' . $action, $methods, $requirements];
        }
        $expected[$config['routes']['runtime_config']['name']] = ['/admin' . $config['routes']['runtime_config']['path'], VaultRuntimeConfigController::class . '::__invoke', ['GET', 'POST'], []];
        foreach (self::LEGACY_EXTENSION_MAP as $key => [$action, $methods]) {
            $route                    = $config['browser_extension']['routes'][$key];
            $expected[$route['name']] = ['/admin' . $route['path'], VaultBrowserExtensionController::class . '::' . $action, $methods, []];
        }

        $actual = [];
        foreach ($collection->all() as $name => $route) {
            $actual[$name] = [$route->getPath(), $route->getDefault('_controller'), $route->getMethods(), $route->getRequirements()];
            // Internal attribute option must not leak into the generated routes.
            self::assertArrayNotHasKey(VaultRouteLoader::ROUTE_KEY_OPTION, $route->getOptions());
        }

        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function testAttributeDefaultsMatchConfigurationDefaults(): void
    {
        $config = $this->defaultConfig();
        $checks = [
            VaultManageController::class           => $config['routes'],
            VaultRuntimeConfigController::class    => $config['routes'],
            VaultBrowserExtensionController::class => $config['browser_extension']['routes'],
        ];

        $seen = 0;
        foreach ($checks as $class => $routes) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                    $route = $attribute->newInstance();
                    $key   = $route->options[VaultRouteLoader::ROUTE_KEY_OPTION];
                    self::assertSame($routes[$key]['path'], $route->path, $class . '::' . $method->getName());
                    self::assertSame($routes[$key]['name'], $route->name, $class . '::' . $method->getName());
                    ++$seen;
                }
            }
        }

        self::assertSame(23, $seen);
    }

    /**
     * @return array{routes: array<string, array{path: string, name: string}>, browser_extension: array{routes: array<string, array{path: string, name: string}>}}
     */
    private function defaultConfig(): array
    {
        /** @var array{routes: array<string, array{path: string, name: string}>, browser_extension: array{routes: array<string, array{path: string, name: string}>}} $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => 'REDACTED-TEST',
        ]]);

        return $config;
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
