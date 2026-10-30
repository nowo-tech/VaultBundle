<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Routing;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Controller\VaultBrowserExtensionController;
use Nowo\VaultBundle\Controller\VaultManageController;
use Nowo\VaultBundle\Controller\VaultRuntimeConfigController;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Builds the bundle routes from the controllers' #[Route] attributes (REQ-SF-004).
 *
 * Each attribute declares the HTTP methods, requirements and the default path / name of one action, and carries the
 * configuration key in the {@see self::ROUTE_KEY_OPTION} option. The loader keeps the public configuration contract:
 * the path and name of every route come from `nowo_vault.routes.<key>` / `nowo_vault.browser_extension.routes.<key>`
 * (runtime config when enabled) and `web_ui.path_prefix` (formerly `route_prefix`) is prepended, exactly as before the attributes existed.
 */
final class VaultRouteLoader extends Loader
{
    /** Route option carrying the `routes.<key>` configuration key of an action. */
    public const ROUTE_KEY_OPTION = 'nowo_vault_route';

    /** Requirement shared by every `{id}` / `{grantId}` placeholder (UUID). */
    public const UUID_REQUIREMENT = '[0-9a-f-]{36}';

    /**
     * Stores the runtime configuration source and the feature flags that decide which controllers are routed.
     *
     * @param array<string, array{path: string, name: string}> $browserExtensionRoutes
     */
    public function __construct(
        private readonly VaultRuntimeConfigProvider $runtimeConfig,
        private readonly bool $configStorageEnabled,
        private readonly bool $browserExtensionEnabled,
        private readonly array $browserExtensionRoutes,
        private readonly bool $webUiEnabled = true,
    ) {
    }

    /**
     * Returns the bundle route collection for the `nowo_vault` import type.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $config = $this->runtimeConfig->get();
        /** @var array<string, array{path: string, name: string}> $routes */
        $routes      = $config['routes'];
        $routePrefix = (string) $config['route_prefix'];
        $collection  = new RouteCollection();

        // web_ui.enabled: false keeps only the browser-extension API.
        if ($this->webUiEnabled) {
            $this->addControllerRoutes($collection, VaultManageController::class, $routes, $routePrefix);

            if ($this->configStorageEnabled) {
                $this->addControllerRoutes($collection, VaultRuntimeConfigController::class, $routes, $routePrefix);
            }
        }

        if ($this->browserExtensionEnabled) {
            $this->addControllerRoutes($collection, VaultBrowserExtensionController::class, $this->browserExtensionRoutes, $routePrefix);
        }

        return $collection;
    }

    /**
     * Tells the routing resolver that this loader handles `type: nowo_vault` imports.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_vault';
    }

    /**
     * Adds one route per #[Route]-attributed public action of $controllerClass, with the configured path and name.
     *
     * @param class-string $controllerClass
     * @param array<string, array{path: string, name: string}> $configuredRoutes
     */
    private function addControllerRoutes(
        RouteCollection $collection,
        string $controllerClass,
        array $configuredRoutes,
        string $routePrefix,
    ): void {
        foreach ((new ReflectionClass($controllerClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                $declared = $attribute->newInstance();
                $key      = (string) ($declared->options[self::ROUTE_KEY_OPTION] ?? '');
                // Unconfigured keys are skipped, as the pre-attribute loader did for optional routes (the manage keys
                // always exist: the runtime config provider refills them from the YAML defaults).
                if (!isset($configuredRoutes[$key])) {
                    continue;
                }

                $collection->add(
                    $configuredRoutes[$key]['name'],
                    new Route(
                        $routePrefix . $configuredRoutes[$key]['path'],
                        ['_controller' => $controllerClass . '::' . $method->getName()],
                        $declared->requirements,
                        [],
                        '',
                        [],
                        $declared->methods,
                    ),
                );
            }
        }
    }
}
