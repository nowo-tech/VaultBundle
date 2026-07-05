<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use Nowo\VaultBundle\Service\VaultPayloadReencryptionService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class VaultPayloadReencryptionServiceTest extends TestCase
{
    public function testReencryptsAllItemsWithNewKey(): void
    {
        $oldKey    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $oldCrypto = new SodiumVaultPayloadCryptographer($oldKey);
        $newCrypto = new SodiumVaultPayloadCryptographer($newKey);

        $item = new VaultItem(
            VaultItemType::Login,
            'Demo',
            new TestUser('1'),
            $oldCrypto->encrypt(['username' => 'demo', 'password' => 'secret']),
        );

        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(1);
        $repository->expects(self::once())
            ->method('findBatch')
            ->with(0, 50, true)
            ->willReturn([$item]);
        $repository->expects(self::once())
            ->method('saveBatch')
            ->with(self::callback(static function (array $saved) use ($item, $newCrypto): bool {
                self::assertSame([$item], $saved);
                self::assertSame(
                    ['username' => 'demo', 'password' => 'secret'],
                    $newCrypto->decrypt($item->getCiphertext()),
                );

                return true;
            }));

        $service = new VaultPayloadReencryptionService($repository, $this->createResolver($newKey));
        $result  = $service->reencrypt($oldKey, $newKey);

        self::assertSame(1, $result->totalItems);
        self::assertSame(1, $result->processedItems);
        self::assertFalse($result->dryRun);
    }

    public function testDryRunDoesNotPersist(): void
    {
        $oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $cipher = (new SodiumVaultPayloadCryptographer($oldKey))->encrypt(['a' => 1]);
        $item   = new VaultItem(VaultItemType::SecureNote, 'Note', new TestUser('1'), $cipher);

        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(1);
        $repository->method('findBatch')->willReturn([$item]);
        $repository->expects(self::never())->method('saveBatch');

        $service = new VaultPayloadReencryptionService($repository, $this->createResolver($newKey));
        $result  = $service->reencrypt($oldKey, $newKey, dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertSame($cipher, $item->getCiphertext());
    }

    public function testRejectsIdenticalKeys(): void
    {
        $key = SodiumVaultPayloadCryptographer::generateKeyBase64();

        $service = new VaultPayloadReencryptionService(
            $this->createMock(VaultItemRepositoryInterface::class),
            $this->createResolver($key),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->reencrypt($key, $key);
    }

    public function testWrapsDecryptFailuresWithItemId(): void
    {
        $oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $item   = new VaultItem(VaultItemType::SecureNote, 'Note', new TestUser('1'), 'invalid-ciphertext');

        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(1);
        $repository->method('findBatch')->willReturn([$item]);

        $service = new VaultPayloadReencryptionService($repository, $this->createResolver($newKey));

        try {
            $service->reencrypt($oldKey, $newKey);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($item->getId(), $exception->getMessage());
        }
    }

    public function testInvokesProgressCallback(): void
    {
        $oldKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $item   = new VaultItem(
            VaultItemType::Login,
            'Demo',
            new TestUser('1'),
            (new SodiumVaultPayloadCryptographer($oldKey))->encrypt(['username' => 'u']),
        );

        $repository = $this->createMock(VaultItemRepositoryInterface::class);
        $repository->method('countAll')->willReturn(1);
        $repository->method('findBatch')->willReturn([$item]);

        $lastProgress = null;
        $service      = new VaultPayloadReencryptionService($repository, $this->createResolver($newKey));
        $service->reencrypt($oldKey, $newKey, onProgress: static function (int $processed, int $total) use (&$lastProgress): void {
            $lastProgress = [$processed, $total];
        });

        self::assertSame([1, 1], $lastProgress);
    }

    private function createResolver(string $encryptionKey): VaultRuntimeConfigResolver
    {
        $baseline = VaultRuntimeConfigFactory::baseline(['encryption_key' => $encryptionKey]);

        return new VaultRuntimeConfigResolver(
            new \Nowo\VaultBundle\Config\VaultRuntimeConfigProvider($baseline, false, $this->createMock(\Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface::class), new \Symfony\Component\Cache\Adapter\ArrayAdapter()),
            $baseline,
            false,
        );
    }
}
