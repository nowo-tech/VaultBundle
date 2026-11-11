<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Connection;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Tests\App\Worker\DbalScopeAccessResolver;
use Nowo\VaultBundle\Tests\App\Worker\NoopServicesResetter;
use Nowo\VaultBundle\VaultBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use const PHP_VERSION_ID;

/**
 * Worker kernel (1.17.1, REQ-RUNTIME-002): FrankenPHP worker mode with kernel reset false. Scope vaults on with a
 * database-backed host resolver ({@see DbalScopeAccessResolver}), deny_status 404, and `services_resetter` replaced
 * by a no-op ({@see NoopServicesResetter}), so `kernel.reset` never runs between requests. Own cache directory (own
 * container and SQLite file).
 */
final class WorkerScopesKernel extends BaseKernel
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
                'scopes'   => ['enabled' => true, 'access_resolver' => DbalScopeAccessResolver::class],
                'security' => ['deny_status' => 404, 'allow_unlisted_grantees' => true],
            ]);
            $container->register(DbalScopeAccessResolver::class, DbalScopeAccessResolver::class)
                ->setArgument('$connection', new Reference(Connection::class));

            // Hundreds of expected 404s: keep the default stderr logger quiet.
            $container->register('logger', NullLogger::class);

            if (PHP_VERSION_ID >= 80400) {
                $container->loadFromExtension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
            }
        });
        $loader->load($this->getProjectDir() . '/config/services.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        // After FrameworkBundle's ResettableServicePass: the kernel still calls services_resetter, which resets nothing.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->setDefinition('services_resetter', (new Definition(NoopServicesResetter::class))->setPublic(true));
            }
        }, PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/worker_scopes_' . $this->environment;
    }
}
