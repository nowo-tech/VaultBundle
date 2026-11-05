<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Doctrine\QueryCounterMiddleware;
use Nowo\VaultBundle\Tests\App\Security\TestReauthChecker;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\VaultBundle;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use const PHP_VERSION_ID;

/**
 * Test kernel variant (1.16.0): scope vaults on (static {@see TestScopeAccessResolver}), deny_status 404, temporary
 * grants in the share form, the catalogue, the health report (max 3 items, policy enforced on save), the decrypted export (static {@see TestReauthChecker}, max 5 items), a persistent reveal rate-limit pool, the profiler, a query counter, a {@see StaticTestClock} as the clock (public `test.mock_clock`), the fail-closed grantee default and
 * its own cache directory (own container and SQLite file).
 */
final class ScopesKernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new NowoUiKitBundle();
        yield new NowoFormKitBundle();
        yield new VaultBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->getProjectDir() . '/config/{packages}/*.{yaml,yml}', 'glob');
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('nowo_vault', [
                'scopes'    => ['enabled' => true, 'access_resolver' => TestScopeAccessResolver::class],
                'security'  => ['deny_status' => 404, 'allow_unlisted_grantees' => true],
                'grants'    => ['temporary' => ['ui' => true, 'max_permission' => 'write']],
                'catalogue' => ['enabled' => true],
                'reveal'    => ['rate_limit' => ['cache_pool' => 'test.vault_secret_access_cache']],
                'health'    => ['enabled' => true, 'max_items' => 3, 'cache_pool' => 'test.vault_secret_access_cache', 'policy' => ['enforce_on_save' => true]],
                'export'    => ['enabled' => true, 'reauth_checker' => TestReauthChecker::class, 'max_items' => 5, 'cache_pool' => 'test.vault_secret_access_cache'],
            ]);
            // Profiles of every request, to prove that the routes returning decrypted values store none.
            $container->loadFromExtension('framework', ['profiler' => ['enabled' => true, 'collect' => true], 'lock' => 'flock']);
            $container->register(QueryCounterMiddleware::class, QueryCounterMiddleware::class)->addTag('doctrine.middleware');
            $container->register(TestScopeAccessResolver::class, TestScopeAccessResolver::class);
            $container->register(TestReauthChecker::class, TestReauthChecker::class);
            // Host-facing services no bundle code uses: kept (and reachable) through public test aliases.
            $container->setAlias('test.personal_data_exporter', VaultPersonalDataExporter::class)->setPublic(true);
            $container->setAlias('test.personal_data_eraser', VaultPersonalDataEraser::class)->setPublic(true);
            $container->register('test.mock_clock', StaticTestClock::class)->setPublic(true);
            $container->setAlias(ClockInterface::class, 'test.mock_clock')->setPublic(true);

            if (PHP_VERSION_ID >= 80400) {
                $container->loadFromExtension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
            }
        });
        $loader->load($this->getProjectDir() . '/config/services.yaml');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/scopes_' . $this->environment;
    }
}
