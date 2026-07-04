<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection;

use Nowo\VaultBundle\DependencyInjection\Configuration;
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
        self::assertArrayHasKey('index', $config['routes']);
    }
}

final class SodiumVaultPayloadCryptographerTestKey
{
    public const VALID = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
}
