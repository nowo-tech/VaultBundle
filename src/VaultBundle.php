<?php

declare(strict_types=1);

namespace Nowo\VaultBundle;

use Nowo\VaultBundle\DependencyInjection\Compiler\BrowserExtensionUserCheckerPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\VaultBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
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
