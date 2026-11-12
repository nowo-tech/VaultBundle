<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Service\VaultPersonalDataEraser;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
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
 * Test kernel variant of the 1.18.0 options (the Symfony Beacon adoption): sharing.enabled false, a host note
 * template, Move to trash in the row kebab menu and database.conditional_mapping with the database-backed
 * configuration and the browser extension off (vault_settings / vault_extension_tokens unmapped). Own cache directory.
 */
final class BeaconAdoptionKernel extends BaseKernel
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
                'web_ui'            => ['host_note_template' => 'host_note.html.twig', 'destructive_actions_in_menu' => true],
                'sharing'           => ['enabled' => false],
                'database'          => ['conditional_mapping' => true],
                'browser_extension' => ['enabled' => false],
            ]);
            // Host-facing services no bundle code uses: kept (and reachable) through public test aliases.
            $container->setAlias('test.personal_data_exporter', VaultPersonalDataExporter::class)->setPublic(true);
            $container->setAlias('test.personal_data_eraser', VaultPersonalDataEraser::class)->setPublic(true);

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
        return $this->getProjectDir() . '/var/cache/beacon_adoption_' . $this->environment;
    }
}
