<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\VaultBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

/**
 * Test kernel variant (REQ-SF-004 rule 7): the routing is only the Symfony default `routing.controllers` resource of a
 * Symfony >= 7.3 skeleton, without the nowo_vault route loader; web UI, runtime config and browser extension API on.
 * Own cache directory.
 */
final class HostControllersRoutesKernel extends BaseKernel
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
            $container->loadFromExtension('framework', [
                'router' => ['resource' => '%kernel.project_dir%/config/routes_controllers_only.yaml', 'type' => 'yaml'],
            ]);
            $container->loadFromExtension('nowo_vault', [
                'config_storage'    => ['enabled' => true],
                'browser_extension' => ['enabled' => true],
            ]);
        });
        $loader->load($this->getProjectDir() . '/config/services.yaml');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/host_controllers_routes_' . $this->environment;
    }
}
