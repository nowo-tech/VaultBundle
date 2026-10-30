<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Nowo\VaultBundle\Tests\App\HostControllersRoutesKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function class_exists;
use function str_starts_with;

/**
 * REQ-SF-004 rule 7: the vault routes are opt-in. A host whose routing is only the Symfony default
 * `routing.controllers` resource (no `type: nowo_vault` import) must not get any vault route, not even the browser
 * extension API.
 */
final class VaultRoutesOptInIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return HostControllersRoutesKernel::class;
    }

    public function testRoutingControllersResourceDoesNotRegisterVaultRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('The routing.controllers resource needs symfony/routing >= 7.3.');
        }

        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $vaultRoutes = array_values(array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $name): bool => str_starts_with($name, 'nowo_vault'),
        ));

        self::assertSame([], $vaultRoutes, 'Vault routes must exist only where the host imports the nowo_vault loader.');
    }
}
