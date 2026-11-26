<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\UserKitBundle\NowoUserKitBundle;
use Nowo\UserKitBundle\PersonalData\PersonalDataErasureInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporterInterface;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\PersonalData\FailingHostEraser;
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

/**
 * Test kernel variant (2.5.0): nowo-tech/user-kit-bundle with `personal_data.enabled` (built-in sections and erasers
 * off), the vault's UserKit bridges, scope vaults, `events.after_host_commit` and a failing host eraser at priority 0.
 * Environment `test_off` sets `nowo_vault.personal_data.user_kit: false`; environment `database` runs on
 * VAULT_TEST_DATABASE_URL. Public aliases `test.user_kit.eraser` and
 * `test.user_kit.exporter`.
 */
final class UserKitKernel extends BaseKernel
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
        yield new NowoUserKitBundle();
        yield new VaultBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->getProjectDir() . '/config/{packages}/*.{yaml,yml}', 'glob');
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('nowo_vault', [
                'scopes'        => ['enabled' => true, 'access_resolver' => TestScopeAccessResolver::class],
                'security'      => ['allow_unlisted_grantees' => true],
                'events'        => ['after_host_commit' => true],
                'personal_data' => ['user_kit' => $this->environment !== 'test_off', 'batch_size' => 2],
            ]);
            $container->loadFromExtension('nowo_user_kit', [
                'user_class'    => User::class,
                'personal_data' => ['enabled' => true, 'builtin_providers' => false, 'builtin_erasers' => false],
            ]);
            $container->register(TestScopeAccessResolver::class, TestScopeAccessResolver::class);
            $container->register(FailingHostEraser::class, FailingHostEraser::class)
                ->addTag('nowo_user_kit.personal_data.eraser', ['priority' => 0]);
            $container->setAlias('test.user_kit.eraser', PersonalDataErasureInterface::class)->setPublic(true);
            $container->setAlias('test.user_kit.exporter', PersonalDataExporterInterface::class)->setPublic(true);
            if ($this->environment === 'database') {
                // REQ-RUNTIME-003: the same kernel on a database server (VAULT_TEST_DATABASE_URL, group "database").
                $container->loadFromExtension('doctrine', ['dbal' => ['url' => '%env(VAULT_TEST_DATABASE_URL)%']]);
            }
            $container->register('test.mock_clock', StaticTestClock::class)->setPublic(true);
            $container->setAlias(ClockInterface::class, 'test.mock_clock')->setPublic(true);
        });
        $loader->load($this->getProjectDir() . '/config/services.yaml');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/user_kit_' . $this->environment;
    }
}
