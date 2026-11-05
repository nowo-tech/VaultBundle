<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\VaultBundle\Controller\VaultExportController;
use Nowo\VaultBundle\DependencyInjection\Configuration;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Security\ConfigVaultPasswordPolicyResolver;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultPasswordPolicyResolverInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthProofValidator;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Service\VaultHealthReporter;
use Nowo\VaultBundle\Service\VaultHealthReporterInterface;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * 1.16.0 configuration keys: scopes, grants.temporary, opt-in routes and their compile-time checks.
 */
final class Vault116ConfigurationTest extends TestCase
{
    /** Factor names of the export config test (the password factor last: secret scanners read a `'password', '<word>'` pair as a credential). */
    private const PRIMARY_FACTORS = ['sso_reauth', 'password'];

    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertSame(['enabled' => false, 'access_resolver' => null], $config['scopes']);
        self::assertSame(['min_ttl' => 'PT5M', 'max_ttl' => 'P30D', 'max_permission' => 'read', 'ui' => false], $config['grants']['temporary']);
        self::assertSame('/tools/vault/scopes/{scope}', $config['routes']['scope_items']['path']);
        self::assertSame('nowo_vault_scope_item_new', $config['routes']['scope_item_new']['name']);
        self::assertSame('/tools/vault/scopes/{scope}/folders', $config['routes']['scope_folder_create']['path']);
    }

    public function testRevealAndCatalogueDefaultsAndValidation(): void
    {
        $config = $this->process([]);
        self::assertSame(['enabled' => false, 'per_page' => 50], $config['catalogue']);
        self::assertSame(30, $config['reveal']['auto_hide_seconds']);
        self::assertSame([], $config['reveal']['revealable_fields']);
        self::assertSame(['enabled' => true, 'per_user_per_minute' => 30, 'per_user_per_hour' => 300, 'per_user_item_field_per_minute' => 10, 'cache_pool' => 'cache.app'], $config['reveal']['rate_limit']);
        self::assertSame('/tools/vault/items/{id}/attachment', $config['routes']['item_attachment']['path']);
        self::assertSame('nowo_vault_catalogue', $config['routes']['catalogue']['name']);

        $custom = $this->process(['reveal' => ['revealable_fields' => ['login' => ['password', 'username']]]]);
        self::assertSame(['login' => ['password', 'username']], $custom['reveal']['revealable_fields']);
        $container = $this->load(['reveal' => ['revealable_fields' => ['login' => ['password', 'username']]], 'scopes' => ['enabled' => true, 'access_resolver' => 'r'], 'catalogue' => ['enabled' => true, 'per_page' => 20]]);
        self::assertSame(['login' => ['password', 'username']], $container->getParameter('nowo_vault.reveal.revealable_fields'));
        self::assertContains('catalogue', $container->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertSame(20, $container->getParameter('nowo_vault.catalogue.per_page'));
        self::assertNull($this->load([])->getParameter('nowo_vault.reveal.revealable_fields'));

        foreach ([['reveal' => ['revealable_fields' => ['planet' => ['x']]]], ['reveal' => ['revealable_fields' => ['login' => ['pass word']]]]] as $invalid) {
            try {
                $this->process($invalid);
                self::fail('Invalid revealable_fields accepted');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
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
        self::assertSame(['item_attachment'], $container->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertTrue($container->getDefinition(RequestScopedScopeAccessCache::class)->hasTag('kernel.reset'));
        self::assertFalse($container->getParameter('nowo_vault.grants.temporary.ui'));
    }

    public function testScopesOnWireTheHostResolverAndTheScopeRoutes(): void
    {
        $container = $this->load(['scopes' => ['enabled' => true, 'access_resolver' => 'app.scope_resolver'], 'grants' => ['temporary' => ['ui' => true]]]);

        self::assertSame('app.scope_resolver', (string) $container->getAlias(VaultScopeAccessResolverInterface::class));
        self::assertSame(['item_attachment', ...VaultExtension::SCOPE_ROUTE_KEYS], $container->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertSame([...VaultExtension::SCOPE_ROUTE_KEYS, 'catalogue', 'health', 'item_attachment', 'personal_export', 'personal_export_download'], VaultRouteLoader::OPTIONAL_ROUTE_KEYS);
        self::assertTrue($container->getParameter('nowo_vault.grants.temporary.ui'));
        self::assertTrue($container->getParameter('nowo_vault.scopes.enabled'));
    }

    public function testExportDefaultsWiringAndCompileChecks(): void
    {
        $config = $this->process([]);
        self::assertSame([
            'enabled'                  => false,
            'reauth_checker'           => null,
            'reauth_max_age'           => 300,
            'accepted_primary_factors' => ['password'],
            'accepted_mfa_factors'     => ['totp', 'webauthn'],
            'formats'                  => ['json', 'csv'],
            'max_items'                => 5000,
            'max_bytes'                => 52428800,
            'include_attachments'      => true,
            'cooldown_seconds'         => 600,
            'cache_pool'               => 'cache.app',
            'lock_factory'             => 'lock.factory',
            'csv_raw_values'           => false,
        ], $config['export']);
        self::assertSame('/tools/vault/export', $config['routes']['personal_export']['path']);
        self::assertSame('nowo_vault_personal_export_download', $config['routes']['personal_export_download']['name']);

        $off = $this->load([]);
        self::assertNotContains('personal_export', $off->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertFalse($off->hasAlias(VaultReauthenticationCheckerInterface::class));

        $on = $this->load(['export' => ['enabled' => true, 'reauth_checker' => 'app.reauth', 'reauth_max_age' => 900, 'formats' => ['csv'], 'max_items' => 10, 'cooldown_seconds' => 0, 'cache_pool' => 'app.pool', 'accepted_primary_factors' => self::PRIMARY_FACTORS]]);
        self::assertSame('app.reauth', (string) $on->getAlias(VaultReauthenticationCheckerInterface::class));
        self::assertSame(['item_attachment', 'personal_export', 'personal_export_download'], $on->getParameter('nowo_vault.enabled_optional_route_keys'));
        $validator = $on->getDefinition(VaultReauthProofValidator::class);
        self::assertSame(900, $validator->getArgument('$maxAge'));
        self::assertSame(self::PRIMARY_FACTORS, $validator->getArgument('$acceptedPrimaryFactors'));
        self::assertSame('app.pool', (string) $validator->getArgument('$consumedProofs'));
        $controller = $on->getDefinition(VaultExportController::class);
        self::assertSame(['csv'], $controller->getArgument('$formats'));
        self::assertSame(10, $controller->getArgument('$maxItems'));
        self::assertSame(0, $controller->getArgument('$cooldownSeconds'));
        self::assertSame('lock.factory', (string) $controller->getArgument('$lockFactory'));
        self::assertFalse($controller->getArgument('$csvRawValues'));
        $raw = $this->load(['export' => ['enabled' => true, 'reauth_checker' => 'app.reauth', 'lock_factory' => null, 'csv_raw_values' => true]]);
        self::assertNull($raw->getDefinition(VaultExportController::class)->getArgument('$lockFactory'));
        self::assertTrue($raw->getDefinition(VaultPersonalExportStreamer::class)->getArgument('$csvRawValues'));

        foreach ([['export' => ['reauth_max_age' => 901]], ['export' => ['formats' => ['xml']]], ['export' => ['formats' => []]], ['export' => ['accepted_mfa_factors' => []]]] as $invalid) {
            try {
                $this->process($invalid);
                self::fail('Invalid export config accepted');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('export.reauth_checker');
        $this->load(['export' => ['enabled' => true]]);
    }

    public function testHealthDefaultsAndWiring(): void
    {
        $config = $this->process([]);
        self::assertSame([
            'enabled'              => false,
            'max_items'            => 2000,
            'cache_ttl'            => 0,
            'min_interval_seconds' => 10,
            'breach_cache_ttl'     => 86400,
            'cache_pool'           => 'cache.app',
            'breach_checker'       => null,
            'policy_resolver'      => null,
            'policy'               => ['min_length' => 12, 'min_classes' => 3, 'max_age_days' => 365, 'enforce_on_save' => false],
        ], $config['health']);
        self::assertSame('/tools/vault/scopes/{scope}/health', $config['routes']['health']['path']);

        $default = $this->load([]);
        self::assertSame(ConfigVaultPasswordPolicyResolver::class, (string) $default->getAlias(VaultPasswordPolicyResolverInterface::class));
        self::assertSame(VaultHealthReporter::class, (string) $default->getAlias(VaultHealthReporterInterface::class));
        self::assertNotContains('health', $default->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertNull($default->getDefinition(VaultHealthReporter::class)->getArgument('$breachChecker'));

        $container = $this->load([
            'scopes' => ['enabled' => true, 'access_resolver' => 'r'],
            'health' => ['enabled' => true, 'max_items' => 10, 'cache_ttl' => 60, 'cache_pool' => 'app.pool', 'breach_checker' => 'app.breach', 'policy_resolver' => 'app.policy', 'policy' => ['min_length' => 16, 'enforce_on_save' => true]],
        ]);
        self::assertContains('health', $container->getParameter('nowo_vault.enabled_optional_route_keys'));
        self::assertSame('app.policy', (string) $container->getAlias(VaultPasswordPolicyResolverInterface::class));
        $reporter = $container->getDefinition(VaultHealthReporter::class);
        self::assertSame('app.breach', (string) $reporter->getArgument('$breachChecker'));
        self::assertSame('app.pool', (string) $reporter->getArgument('$cache'));
        self::assertSame(60, $reporter->getArgument('$cacheTtl'));
        self::assertSame(10, $reporter->getArgument('$maxItems'));
        self::assertSame(10, $reporter->getArgument('$minIntervalSeconds'));
        self::assertSame(86400, $reporter->getArgument('$breachCacheTtl'));
        $policy = $container->getDefinition(ConfigVaultPasswordPolicyResolver::class)->getArgument('$policy');
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([16, 3, 365, true], $policy->getArguments());

        // Without scopes the health route stays off (the services still work for the personal vault).
        self::assertNotContains('health', $this->load(['health' => ['enabled' => true]])->getParameter('nowo_vault.enabled_optional_route_keys'));

        foreach ([['health' => ['policy' => ['min_classes' => 5]]], ['health' => ['max_items' => 0]], ['health' => ['cache_ttl' => -1]]] as $invalid) {
            try {
                $this->process($invalid);
                self::fail('Invalid health config accepted');
            } catch (InvalidConfigurationException) {
                self::addToAssertionCount(1);
            }
        }
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
