<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Routing;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Optional (browser extension) route keys left out of the configuration are skipped.
 *
 * Manage / runtime_config keys cannot go missing: VaultRuntimeConfigProvider refills `routes` from the defaults.
 */
final class VaultRouteLoaderMissingKeysTest extends TestCase
{
    public function testMissingExtensionKeysAreSkipped(): void
    {
        $extension = [
            'login'  => ['path' => '/ext/login', 'name' => 'ext_login'],
            'logins' => ['path' => '/ext/logins', 'name' => 'ext_logins'],
        ];
        $loader = new VaultRouteLoader(
            new VaultRuntimeConfigProvider(
                VaultRuntimeConfigFactory::baseline(),
                false,
                $this->createMock(VaultSettingsRepositoryInterface::class),
                new ArrayAdapter(),
            ),
            false,
            true,
            $extension,
        );

        $collection = $loader->load('.', 'nowo_vault');

        self::assertSame('/ext/logins', $collection->get('ext_logins')?->getPath());
        self::assertSame(['POST', 'OPTIONS'], $collection->get('ext_login')?->getMethods());
        self::assertNull($collection->get('nowo_vault_extension_me'));
        self::assertNull($collection->get('nowo_vault_extension_logout'));
        self::assertNull($collection->get('nowo_vault_runtime_config'), 'config storage disabled');
        self::assertNotNull($collection->get('nowo_vault_items'));
    }
}
