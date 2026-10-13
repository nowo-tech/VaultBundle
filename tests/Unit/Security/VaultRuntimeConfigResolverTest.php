<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class VaultRuntimeConfigResolverTest extends TestCase
{
    public function testThrowsWhenEncryptionKeyMissing(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => '']);
        $provider = new VaultRuntimeConfigProvider(
            $baseline,
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            new ArrayAdapter(),
        );
        $resolver = new VaultRuntimeConfigResolver($provider, $baseline, false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nowo_vault.encryption_key is not configured in YAML or database.');

        $resolver->resolveEncryptionKeyBase64();
    }

    public function testYamlKeyCountsAsExistingKey(): void
    {
        $key      = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $key]);
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn(null);
        $resolver = new VaultRuntimeConfigResolver(
            new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter()),
            $baseline,
            true,
        );

        self::assertTrue($resolver->hasYamlEncryptionKey());
        self::assertTrue($resolver->hasConfiguredEncryptionKey());
    }

    public function testDatabaseKeyCountsAsExistingKeyOnlyWhenStorageEnabled(): void
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => '']);
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn(new VaultSettings(encryptionKey: SodiumVaultPayloadCryptographer::generateKeyBase64()));

        $enabled = new VaultRuntimeConfigResolver(
            new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter()),
            $baseline,
            true,
        );
        self::assertFalse($enabled->hasYamlEncryptionKey());
        self::assertTrue($enabled->hasConfiguredEncryptionKey());

        $disabled = new VaultRuntimeConfigResolver(
            new VaultRuntimeConfigProvider($baseline, false, $settings, new ArrayAdapter()),
            $baseline,
            false,
        );
        self::assertFalse($disabled->hasConfiguredEncryptionKey());

        $emptySettings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $emptySettings->method('findByScope')->willReturn(null);
        $none = new VaultRuntimeConfigResolver(
            new VaultRuntimeConfigProvider($baseline, true, $emptySettings, new ArrayAdapter()),
            $baseline,
            true,
        );
        self::assertFalse($none->hasConfiguredEncryptionKey());
    }
}
