<?php

declare(strict_types=1);

namespace Nowo\VaultBundle;

use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\ConditionalMappingPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\ExportLockFactoryPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\VaultPortsPass;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Password and secrets vault for Symfony applications.
 */
final class VaultBundle extends Bundle
{
    public const TRANSLATION_DOMAIN = 'NowoVaultBundle';

    /**
     * Registers the bundle compiler passes.
     */
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new BrowserExtensionUserCheckerPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new ExportLockFactoryPass());
        // 1.19.0: before optimization (decorators and bindings are resolved later).
        $container->addCompilerPass(new VaultPortsPass());
        // 1.18.0: before DoctrineBundle's DbalSchemaFilterPass (priority 0), which collects the schema filter tag.
        $container->addCompilerPass(new ConditionalMappingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        // 1.19.0: before DoctrineBundle's MiddlewaresPass, which collects the rollback middleware tag.
        $container->addCompilerPass(new PrivateEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * Returns the bundle DI extension (alias nowo_vault).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new VaultExtension();
        }

        return $this->extension;
    }
}
