<?php

declare(strict_types=1);

namespace Nowo\VaultBundle;

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

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new VaultExtension();
        }

        return $this->extension;
    }
}
