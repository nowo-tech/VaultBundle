<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultRuntimeConfigResolver;
use RuntimeException;

use function count;
use function sprintf;

final readonly class VaultPayloadReencryptionService
{
    public function __construct(
        private VaultItemRepositoryInterface $itemRepository,
        private VaultRuntimeConfigResolver $configResolver,
    ) {
    }

    public function reencrypt(
        string $oldKeyBase64,
        ?string $newKeyBase64 = null,
        int $batchSize = 50,
        bool $includeDeleted = true,
        bool $dryRun = false,
        ?callable $onProgress = null,
    ): VaultPayloadReencryptionResult {
        $newKeyBase64 ??= $this->configResolver->resolveEncryptionKeyBase64();

        if ($oldKeyBase64 === $newKeyBase64) {
            throw new InvalidArgumentException('Old and new encryption keys must differ.');
        }

        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1.');
        }

        $oldCryptographer = new SodiumVaultPayloadCryptographer($oldKeyBase64);
        $newCryptographer = new SodiumVaultPayloadCryptographer($newKeyBase64);

        $total     = $this->itemRepository->countAll($includeDeleted);
        $processed = 0;
        $offset    = 0;

        while ($offset < $total) {
            $batch = $this->itemRepository->findBatch($offset, $batchSize, $includeDeleted);
            if ($batch === []) {
                break;
            }

            $updated = [];

            foreach ($batch as $item) {
                try {
                    $plaintext = $oldCryptographer->decrypt($item->getCiphertext());
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(sprintf('Failed to decrypt vault item %s.', $item->getId()), 0, $exception);
                }

                if (!$dryRun) {
                    $item->setCiphertext($newCryptographer->encrypt($plaintext));
                    $updated[] = $item;
                }

                ++$processed;
            }

            if (!$dryRun) {
                $this->itemRepository->saveBatch($updated);
            }

            if ($onProgress !== null) {
                $onProgress($processed, $total);
            }

            $offset += count($batch);
        }

        return new VaultPayloadReencryptionResult($total, $processed, $dryRun);
    }
}
