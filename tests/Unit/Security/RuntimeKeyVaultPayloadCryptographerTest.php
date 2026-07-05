<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\RuntimeKeyVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RuntimeKeyVaultPayloadCryptographerTest extends TestCase
{
    public function testUsesDatabaseEncryptionKeyOverrideWhenEnabled(): void
    {
        $yamlKey = SodiumVaultPayloadCryptographerTestKey::VALID;
        $dbKey   = base64_encode(str_repeat('b', 32));
        $stored  = new VaultSettings(values: [], encryptionKey: $dbKey);

        $repo = $this->createMock(VaultSettingsRepositoryInterface::class);
        $repo->method('findByScope')->willReturn($stored);

        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $yamlKey]);
        $provider = new VaultRuntimeConfigProvider($baseline, true, $repo, new ArrayAdapter());
        $resolver = new VaultRuntimeConfigResolver($provider, $baseline, true);
        $crypto   = new RuntimeKeyVaultPayloadCryptographer($resolver);

        $payload = ['secret' => 'value'];
        $cipher  = $crypto->encrypt($payload);

        self::assertSame($payload, (new SodiumVaultPayloadCryptographer($dbKey))->decrypt($cipher));
    }

    public function testFallsBackToYamlBootstrapWhenDatabaseDisabled(): void
    {
        $yamlKey  = SodiumVaultPayloadCryptographerTestKey::VALID;
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $yamlKey]);
        $provider = new VaultRuntimeConfigProvider($baseline, false, $this->createMock(VaultSettingsRepositoryInterface::class), new ArrayAdapter());
        $resolver = new VaultRuntimeConfigResolver($provider, $baseline, false);
        $crypto   = new RuntimeKeyVaultPayloadCryptographer($resolver);

        $payload = ['secret' => 'yaml'];
        $cipher  = $crypto->encrypt($payload);

        self::assertSame($payload, $crypto->decrypt($cipher));
    }
}
