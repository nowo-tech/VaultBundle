<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use Nowo\VaultBundle\DependencyInjection\Configuration;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfigurationIsValid(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID,
        ]]);

        self::assertSame('App\\Entity\\User', $config['user_class']);
        self::assertSame('vault_', $config['table_prefix']);
        self::assertArrayNotHasKey('css_framework', $config);
        self::assertTrue($config['database']['private_entity_manager'], 'no effect since 2.0');
        self::assertSame(['enabled' => true, 'path_prefix' => '', 'layout_template' => null, 'destructive_actions_in_menu' => false, 'header_actions' => 'buttons', 'host_note_template' => null, 'templates' => []], $config['web_ui']);
        // 1.18.0 defaults keep the 1.17 behaviour.
        self::assertSame(['enabled' => true], $config['sharing']);
        self::assertFalse($config['database']['conditional_mapping']);
        self::assertFalse($config['config_storage']['enabled']);
        self::assertFalse($config['browser_extension']['enabled']);
        self::assertSame(86_400, $config['browser_extension']['token_ttl']);
        self::assertTrue($config['browser_extension']['login_rate_limit']['enabled']);
        self::assertSame(5, $config['browser_extension']['login_rate_limit']['max_attempts']);
        self::assertSame(20, $config['browser_extension']['login_rate_limit']['max_attempts_per_ip']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame(['ROLE_USER'], $config['security']['access_roles']);
        self::assertSame(403, $config['security']['deny_status']);
        self::assertFalse($config['security']['allow_unlisted_grantees']);
        self::assertSame(['enabled' => true, 'max_requests' => 60, 'window_seconds' => 60, 'cache_pool' => 'cache.app'], $config['security']['secret_access_rate_limit']);
        self::assertArrayHasKey('index', $config['routes']);
        self::assertSame('/tools/vault/items/{id}/reveal/{field}', $config['routes']['item_reveal']['path']);
    }

    /**
     * 2.0: the keys deprecated since 1.15 fail with what to set instead (not the generic "unrecognized option").
     */
    public function testTheRemovedKeysFailWithWhatToSetInstead(): void
    {
        foreach ([
            'route_prefix'  => ['route_prefix' => '/admin'],
            'templates'     => ['templates' => ['layout' => 'base.html.twig']],
            'css_framework' => ['css_framework' => 'bootstrap5'],
        ] as $key => $extra) {
            try {
                $this->process($extra);
                self::fail($key . ' must fail');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('These options were deprecated since 1.15 and are removed in 2.0: "nowo_vault.' . $key . '" (', $exception->getMessage());
                self::assertStringContainsString(Configuration::REMOVED_ROOT_KEYS[$key], $exception->getMessage());
                self::assertStringContainsString('UPGRADING.md "To 2.0.0"', $exception->getMessage());
            }
        }

        $this->expectExceptionMessage('"nowo_vault.route_prefix" (set nowo_vault.web_ui.path_prefix); "nowo_vault.css_framework"');
        $this->process(['route_prefix' => '/a', 'css_framework' => 'tabler']);
    }

    public function testUnknownPageTemplatesAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown web_ui.templates page(s) "dashboard"');

        $this->process(['web_ui' => ['templates' => ['dashboard' => 'x.html.twig']]]);
    }

    public function testWebUiCanBeDisabled(): void
    {
        self::assertFalse($this->process(['web_ui' => ['enabled' => false]])['web_ui']['enabled']);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function process(array $extra): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID,
        ] + $extra]);
    }
}
