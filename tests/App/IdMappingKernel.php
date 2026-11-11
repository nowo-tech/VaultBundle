<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Ddd\DddUserDirectory;
use Nowo\VaultBundle\Tests\App\EventSubscriber\VaultEventRecorderSubscriber;
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
 * Strict DDD host (1.17.0): `nowo_vault.user_mapping: id`, the security user is a plain PHP object
 * ({@see DddUser}, string UUID ids), no host entity is mapped in Doctrine, and the host aliases the user locator port
 * to its own directory. Own cache directory and SQLite file.
 */
final class IdMappingKernel extends BaseKernel
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
        foreach (['framework', 'translation', 'twig'] as $file) {
            $loader->load($this->getProjectDir() . '/config/packages/' . $file . '.yaml');
        }
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => 'sqlite:///%kernel.cache_dir%/ddd.db'],
                'orm'  => ['naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware'] + (PHP_VERSION_ID >= 80400 ? ['enable_native_lazy_objects' => true] : []),
            ]);
            $container->loadFromExtension('security', [
                'providers'      => ['ddd' => ['id' => DddUserDirectory::class]],
                'firewalls'      => ['main' => ['lazy' => true, 'provider' => 'ddd']],
                'access_control' => [['path' => '^/api/vault/extension', 'roles' => 'PUBLIC_ACCESS']],
            ]);
            $container->loadFromExtension('nowo_vault', [
                'user_class'        => DddUser::class,
                'user_mapping'      => 'id',
                'encryption_key'    => '%env(VAULT_ENCRYPTION_KEY)%',
                'browser_extension' => ['enabled' => true, 'user_provider' => DddUserDirectory::class],
                'security'          => ['allow_unlisted_grantees' => true],
            ]);
            $container->register(DddUserDirectory::class, DddUserDirectory::class)->setPublic(true);
            $container->setAlias(VaultUserLocatorInterface::class, DddUserDirectory::class);
            $container->register(VaultEventRecorderSubscriber::class, VaultEventRecorderSubscriber::class)->setAutoconfigured(true);
            $container->setAlias('test.personal_data_exporter', VaultPersonalDataExporter::class)->setPublic(true);
            $container->setAlias('test.personal_data_eraser', VaultPersonalDataEraser::class)->setPublic(true);
            $container->setAlias('test.extension_auth', VaultBrowserExtensionAuthService::class)->setPublic(true);
        });
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/id_mapping_' . $this->environment;
    }
}
