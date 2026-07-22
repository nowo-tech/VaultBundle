<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit;

use Nowo\VaultBundle\VaultBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class VaultBundleTest extends TestCase
{
    public function testTranslationDomain(): void
    {
        self::assertSame('NowoVaultBundle', VaultBundle::TRANSLATION_DOMAIN);
    }

    public function testBuildRegistersCompilerPass(): void
    {
        $container = new ContainerBuilder();
        (new VaultBundle())->build($container);
        self::assertNotEmpty($container->getCompilerPassConfig()->getPasses());
    }

    public function testGetContainerExtensionReturnsVaultExtension(): void
    {
        $bundle = new VaultBundle();
        self::assertSame('nowo_vault', $bundle->getContainerExtension()->getAlias());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
    }
}
