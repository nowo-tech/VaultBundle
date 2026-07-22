<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\VaultBundle\Command\PurgeExtensionTokensCommand;
use Nowo\VaultBundle\Command\ReencryptVaultPayloadsCommand;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\VaultBundle;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class VaultBundleIntegrationTest extends TestCase
{
    private const ENCRYPTION_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public function testExtensionAliasMatchesBundleConfiguration(): void
    {
        $bundle = new VaultBundle();
        self::assertSame('nowo_vault', $bundle->getContainerExtension()->getAlias());
    }

    public function testContainerBuildsCoreServicesFromMinimalConfig(): void
    {
        $container = new ContainerBuilder();
        (new VaultExtension())->load([[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => self::ENCRYPTION_KEY,
        ]], $container);

        self::assertTrue($container->hasAlias(VaultAccessCheckerInterface::class));
        self::assertTrue($container->hasDefinition(VaultRouteLoader::class));
        self::assertTrue($container->hasAlias(VaultItemRepositoryInterface::class));
        self::assertTrue($container->hasAlias(VaultGrantRepositoryInterface::class));
        self::assertTrue($container->hasAlias(VaultExtensionTokenRepositoryInterface::class));
        self::assertTrue($container->hasAlias(VaultTeamMembershipResolverInterface::class));
    }

    public function testExtensionPrependConfiguresAssetsAndDoctrine(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new FrameworkExtension());
        $container->registerExtension(new DoctrineExtension());

        (new VaultExtension())->prepend($container);

        $configs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($configs);
        self::assertSame('/bundles/vault', $configs[0]['assets']['packages']['nowo_vault']['base_path']);

        $doctrine = $container->getExtensionConfig('doctrine');
        self::assertArrayHasKey('orm', $doctrine[0]);
    }

    public function testExtensionUsesCustomAccessCheckerAndTeamResolver(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.vault.access', new Definition(stdClass::class));
        $container->setDefinition('app.vault.teams', new Definition(stdClass::class));

        (new VaultExtension())->load([[
            'user_class'               => 'App\\Entity\\User',
            'encryption_key'           => self::ENCRYPTION_KEY,
            'team_membership_resolver' => 'app.vault.teams',
            'security'                 => ['access_checker' => 'app.vault.access'],
        ]], $container);

        self::assertSame('app.vault.access', (string) $container->getAlias(VaultAccessCheckerInterface::class));
        self::assertSame('app.vault.teams', (string) $container->getAlias(VaultTeamMembershipResolverInterface::class));
    }

    public function testMaintenanceCommandsAreRegisteredInContainer(): void
    {
        $container = new ContainerBuilder();
        (new VaultExtension())->load([[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => self::ENCRYPTION_KEY,
        ]], $container);

        self::assertTrue($container->hasDefinition(PurgeExtensionTokensCommand::class));
        self::assertTrue($container->hasDefinition(ReencryptVaultPayloadsCommand::class));
    }

    public function testPrependSkipsWhenFrameworkMissing(): void
    {
        $container = new ContainerBuilder();
        (new VaultExtension())->prepend($container);
        self::assertFalse($container->hasExtension('framework'));
    }
}
