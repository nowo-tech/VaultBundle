<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
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
 * 1.18.0 (NC2-1): the strict DDD host of {@see IdMappingKernel} with `database.conditional_mapping: true` and the
 * browser extension off, so the token entity is not mapped and its table is usually missing. Environment "database":
 * the server of VAULT_TEST_DATABASE_URL. Own cache directory and SQLite file.
 */
final class ConditionalMappingIdKernel extends BaseKernel
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
        // Environment "database": the MySQL / MariaDB / PostgreSQL server of VAULT_TEST_DATABASE_URL.
        $url = $this->environment === 'database' ? '%env(VAULT_TEST_DATABASE_URL)%' : 'sqlite:///%kernel.cache_dir%/conditional.db';
        $loader->load(static function (ContainerBuilder $container) use ($url): void {
            $container->loadFromExtension('doctrine', [
                'dbal' => ['url' => $url],
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
                'browser_extension' => ['enabled' => false],
                'database'          => ['conditional_mapping' => true],
            ]);
            $container->register(DddUserDirectory::class, DddUserDirectory::class)->setPublic(true);
            $container->setAlias(VaultUserLocatorInterface::class, DddUserDirectory::class);
            $container->register(VaultEventRecorderSubscriber::class, VaultEventRecorderSubscriber::class)->setAutoconfigured(true);
            $container->setAlias('test.personal_data_exporter', VaultPersonalDataExporter::class)->setPublic(true);
            $container->setAlias('test.personal_data_eraser', VaultPersonalDataEraser::class)->setPublic(true);
            $container->setAlias('test.extension_token_repository', VaultExtensionTokenRepositoryInterface::class)->setPublic(true);
        });
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/conditional_mapping_id_' . $this->environment;
    }
}
