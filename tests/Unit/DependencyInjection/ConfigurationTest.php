<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use Nowo\VaultBundle\DependencyInjection\Configuration;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use PHPUnit\Framework\TestCase;
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
        self::assertFalse($config['config_storage']['enabled']);
        self::assertFalse($config['browser_extension']['enabled']);
        self::assertSame(86_400, $config['browser_extension']['token_ttl']);
        self::assertTrue($config['browser_extension']['login_rate_limit']['enabled']);
        self::assertSame(5, $config['browser_extension']['login_rate_limit']['max_attempts']);
        self::assertArrayHasKey('index', $config['routes']);
    }
}
