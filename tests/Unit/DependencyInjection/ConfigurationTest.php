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
        self::assertNull($config['css_framework']);
        self::assertSame(['enabled' => true, 'path_prefix' => '', 'layout_template' => null, 'templates' => []], $config['web_ui']);
        self::assertFalse($config['config_storage']['enabled']);
        self::assertFalse($config['browser_extension']['enabled']);
        self::assertSame(86_400, $config['browser_extension']['token_ttl']);
        self::assertTrue($config['browser_extension']['login_rate_limit']['enabled']);
        self::assertSame(5, $config['browser_extension']['login_rate_limit']['max_attempts']);
        self::assertSame(20, $config['browser_extension']['login_rate_limit']['max_attempts_per_ip']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertSame(['ROLE_USER'], $config['security']['access_roles']);
        self::assertArrayHasKey('index', $config['routes']);
    }

    public function testCssFrameworkAccepted(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID,
            'css_framework'  => 'bootstrap5',
        ]]);

        self::assertSame('bootstrap5', $config['css_framework']);
    }

    public function testInvalidCssFrameworkRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'user_class'     => 'App\\Entity\\User',
            'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID,
            'css_framework'  => 'material',
        ]]);
    }

    public function testDeprecatedKeysAreMappedOntoWebUi(): void
    {
        $config = $this->process([
            'route_prefix' => '/admin',
            'templates'    => [
                'layout' => 'base.html.twig',
                'index'  => 'vault/home.html.twig',
                'items'  => '@NowoVaultBundle/vault/items.html.twig',
                'trash'  => 'vault/trash.html.twig',
            ],
        ]);

        self::assertSame('/admin', $config['web_ui']['path_prefix']);
        self::assertSame('base.html.twig', $config['web_ui']['layout_template']);
        // The 1.x defaults (items) are dropped; index is the alias of home.
        self::assertEqualsCanonicalizing(['home' => 'vault/home.html.twig', 'trash' => 'vault/trash.html.twig'], $config['web_ui']['templates']);
        self::assertArrayNotHasKey('templates', $config);
        self::assertArrayNotHasKey('route_prefix', $config);
    }

    public function testCanonicalKeysWinOverTheDeprecatedOnes(): void
    {
        $config = $this->process([
            'route_prefix' => '/old',
            'templates'    => ['layout' => Configuration::LEGACY_DEFAULT_LAYOUT, 'home' => 'old/home.html.twig', 'index' => 'old/index.html.twig', 'shared' => 'old/shared.html.twig'],
            'web_ui'       => ['path_prefix' => '/new', 'layout_template' => 'new.html.twig', 'templates' => ['home' => 'new/home.html.twig']],
        ]);

        self::assertSame('/new', $config['web_ui']['path_prefix']);
        self::assertSame('new.html.twig', $config['web_ui']['layout_template']);
        self::assertSame(['home' => 'new/home.html.twig', 'shared' => 'old/shared.html.twig'], $config['web_ui']['templates']);
    }

    public function testTheRemovedDefaultLayoutMapsToTheUiKitLayout(): void
    {
        $config = $this->process(['templates' => ['layout' => Configuration::LEGACY_DEFAULT_LAYOUT, 'home' => '@NowoVaultBundle/vault/home.html.twig']]);

        self::assertNull($config['web_ui']['layout_template']);
        self::assertSame([], $config['web_ui']['templates']);

        $nonArray = $this->process(['templates' => null]);
        self::assertSame([], $nonArray['web_ui']['templates']);
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
