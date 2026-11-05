<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Configuration;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.16.0 configuration keys: scopes, grants.temporary, opt-in routes and their compile-time checks.
 */
final class Vault116ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(['enabled' => false, 'access_resolver' => null], $config['scopes']);
        self::assertSame(['min_ttl' => 'PT5M', 'max_ttl' => 'P30D', 'max_permission' => 'read', 'ui' => false], $config['grants']['temporary']);
        self::assertSame('/tools/vault/scopes/{scope}', $config['routes']['scope_items']['path']);
        self::assertSame('nowo_vault_scope_item_new', $config['routes']['scope_item_new']['name']);
        self::assertSame('/tools/vault/scopes/{scope}/folders', $config['routes']['scope_folder_create']['path']);
    }

    public function testExplicitDurationsAreAccepted(): void
    {
        $config = $this->process(['grants' => ['temporary' => ['min_ttl' => 'PT10M', 'max_ttl' => 'P7D', 'max_permission' => 'write']]]);

        self::assertSame(['min_ttl' => 'PT10M', 'max_ttl' => 'P7D', 'max_permission' => 'write', 'ui' => false], $config['grants']['temporary']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidGrantConfigs(): iterable
    {
        yield 'admin temporary grants' => [['grants' => ['temporary' => ['max_permission' => 'admin']]]];
        yield 'min_ttl not a duration' => [['grants' => ['temporary' => ['min_ttl' => '5 minutes']]]];
        yield 'max_ttl not a duration' => [['grants' => ['temporary' => ['max_ttl' => 'PXYZ']]]];
        yield 'max_ttl empty' => [['grants' => ['temporary' => ['max_ttl' => '']]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidGrantConfigs')]
    public function testInvalidGrantConfigIsRefused(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($config);
    }

    public function testScopesWithoutResolverFailTheCompile(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('scopes.access_resolver');
        $this->load(['scopes' => ['enabled' => true]]);
    }

    public function testScopesOffWireTheNullResolverAndNoScopeRoutes(): void
    {
        $container = $this->load([]);

        self::assertSame(NullVaultScopeAccessResolver::class, (string) $container->getAlias(VaultScopeAccessResolverInterface::class));
        self::assertSame([], $container->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertTrue($container->getDefinition(RequestScopedScopeAccessCache::class)->hasTag('kernel.reset'));
        self::assertFalse($container->getParameter('nowo_vault.grants.temporary.ui'));
    }

    public function testScopesOnWireTheHostResolverAndTheScopeRoutes(): void
    {
        $container = $this->load(['scopes' => ['enabled' => true, 'access_resolver' => 'app.scope_resolver'], 'grants' => ['temporary' => ['ui' => true]]]);

        self::assertSame('app.scope_resolver', (string) $container->getAlias(VaultScopeAccessResolverInterface::class));
        self::assertSame(VaultExtension::SCOPE_ROUTE_KEYS, $container->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertSame(VaultExtension::SCOPE_ROUTE_KEYS, VaultRouteLoader::OPTIONAL_ROUTE_KEYS);
        self::assertTrue($container->getParameter('nowo_vault.grants.temporary.ui'));
        self::assertTrue($container->getParameter('nowo_vault.scopes.enabled'));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID,
        ] + $config]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
        (new VaultExtension())->load([['user_class' => 'App\\Entity\\User', 'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID] + $config], $container);

        return $container;
    }
}
