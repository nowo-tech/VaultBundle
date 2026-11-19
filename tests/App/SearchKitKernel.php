<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Bridge\SearchKit\VaultItemSearchDefinition;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\VaultBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function class_exists;

use const PHP_VERSION_ID;

/**
 * Test kernel variant (1.21.0): the SearchKit group switched on and wired by the extension (scope vaults on, `search_kit.priority` 7),
 * reachable through the public alias `test.vault_search_definition` when SearchKit is installed. Own cache directory.
 */
final class SearchKitKernel extends BaseKernel
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
                'scopes'     => ['enabled' => true, 'access_resolver' => TestScopeAccessResolver::class],
                'search_kit' => ['enabled' => true, 'priority' => 7, 'max_candidates' => 50],
            ]);
            $container->register(TestScopeAccessResolver::class, TestScopeAccessResolver::class);
            $container->addCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    if ($container->hasDefinition(VaultItemSearchDefinition::class)) {
                        $container->setAlias('test.vault_search_definition', VaultItemSearchDefinition::class)->setPublic(true);
                    }
                }
            });

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
        // One container per SearchKit presence: a cached container (debug off) never sees SearchKit come or go.
        return $this->getProjectDir() . '/var/cache/searchkit_' . (class_exists(VaultExtension::SEARCH_KIT_DEFINITION_CLASS) ? 'with_' : 'without_') . $this->environment;
    }
}
