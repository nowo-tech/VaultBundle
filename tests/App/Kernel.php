<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\VaultBundle\VaultBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

use const PHP_VERSION_ID;

final class Kernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new VaultBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->getProjectDir() . '/config/{packages}/*.{yaml,yml}', 'glob');
        $loader->load(static function (ContainerBuilder $container): void {
            if (PHP_VERSION_ID >= 80400) {
                // PHP 8.4+: native lazy objects (required with Doctrine Bundle 3 / Symfony 8).
                $container->loadFromExtension('doctrine', [
                    'orm' => [
                        'enable_native_lazy_objects' => true,
                    ],
                ]);

                return;
            }

            // PHP 8.2–8.3: lazy ghosts via symfony/var-exporter (see composer.json require-dev).
            $container->loadFromExtension('doctrine', [
                'orm' => [
                    'auto_generate_proxy_classes' => true,
                ],
            ]);
        });
        $loader->load($this->getProjectDir() . '/config/services.yaml');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }
}
