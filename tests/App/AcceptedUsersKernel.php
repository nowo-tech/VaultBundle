<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Tests\App\Ddd\AppSecurityUser;
use Nowo\VaultBundle\VaultBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use const PHP_VERSION_ID;

/**
 * Relation mapping with a security user that is no Doctrine entity (1.17.0): the default test host plus
 * `nowo_vault.accepted_user_classes: [AppSecurityUser]`. Own cache directory and SQLite file.
 */
final class AcceptedUsersKernel extends BaseKernel
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
            $container->loadFromExtension('nowo_vault', ['accepted_user_classes' => [AppSecurityUser::class]]);
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
        return $this->getProjectDir() . '/var/cache/accepted_' . $this->environment;
    }
}
