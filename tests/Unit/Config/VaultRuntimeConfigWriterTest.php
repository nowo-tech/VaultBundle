<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Config;

use InvalidArgumentException;
use LogicException;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Integration\DoctrineEncryptIntegration;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class VaultRuntimeConfigWriterTest extends TestCase
{
    public function testStoresValidEncryptionKey(): void
    {
        $key      = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn(null);
        $settings->expects(self::once())->method('save')->with(self::callback(
            static fn (VaultSettings $entity): bool => $entity->getEncryptionKey() === $key,
        ));

        $writer = $this->writer($settings, true);
        self::assertTrue($writer->canStoreEncryptionKey());

        $writer->update(['encryption_key' => $key]);
    }

    /**
     * A pasted key that is not a 32-byte libsodium key would make every new item undecryptable.
     */
    public function testRejectsInvalidPastedKeyWithoutSaving(): void
    {
        foreach (['not-a-key', base64_encode(random_bytes(16))] as $invalidKey) {
            $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
            $settings->expects(self::never())->method('save');

            try {
                $this->writer($settings, true)->update(['encryption_key' => $invalidKey, 'max_attachment_bytes' => 10]);
                self::fail('Expected rejection for ' . $invalidKey);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('32-byte libsodium key', $exception->getMessage());
            }
        }
    }

    public function testRefusesKeyStorageWithoutDoctrineEncryptBundle(): void
    {
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->expects(self::never())->method('save');

        $writer = $this->writer($settings, false);
        self::assertFalse($writer->canStoreEncryptionKey());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine-encrypt-bundle');

        $writer->update(['encryption_key' => SodiumVaultPayloadCryptographer::generateKeyBase64()]);
    }

    public function testOtherSettingsAreSavedWithoutDoctrineEncryptBundle(): void
    {
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $settings->method('findByScope')->willReturn(null);
        $settings->expects(self::once())->method('save');

        $this->writer($settings, false)->update(['max_attachment_bytes' => 10]);
    }

    public function testAvailabilityDefaultsToInstalledPackageAndRequiresDatabase(): void
    {
        $settings = $this->createMock(VaultSettingsRepositoryInterface::class);
        $baseline = VaultRuntimeConfigFactory::baseline();
        $provider = new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter());

        self::assertSame(
            DoctrineEncryptIntegration::isAvailable(),
            (new VaultRuntimeConfigWriter(true, $settings, $provider))->canStoreEncryptionKey(),
        );
        self::assertFalse((new VaultRuntimeConfigWriter(false, $settings, $provider, true))->canStoreEncryptionKey());
    }

    private function writer(VaultSettingsRepositoryInterface $settings, bool $storageAvailable): VaultRuntimeConfigWriter
    {
        $baseline = VaultRuntimeConfigFactory::baseline();
        $provider = new VaultRuntimeConfigProvider($baseline, true, $settings, new ArrayAdapter());

        return new VaultRuntimeConfigWriter(true, $settings, $provider, $storageAvailable);
    }
}
