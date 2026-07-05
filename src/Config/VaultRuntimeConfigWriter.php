<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Config;

use LogicException;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;

use function array_key_exists;
use function is_string;

/**
 * Persists runtime configuration overrides to the database and refreshes the cache.
 */
final readonly class VaultRuntimeConfigWriter
{
    public function __construct(
        private bool $databaseEnabled,
        private VaultSettingsRepositoryInterface $settingsRepository,
        private VaultRuntimeConfigProvider $configProvider,
    ) {
    }

    /**
     * @param array<string, mixed> $values Partial runtime config (see VaultRuntimeConfigSchema)
     */
    public function update(array $values): void
    {
        if (!$this->databaseEnabled) {
            throw new LogicException('Database config storage is disabled. Enable nowo_vault.config_storage.enabled in YAML.');
        }

        $filtered = VaultRuntimeConfigSchema::filter($values);
        if ($filtered === []) {
            return;
        }

        $encryptionKey = null;
        if (array_key_exists('encryption_key', $filtered)) {
            $encryptionKey = $filtered['encryption_key'];
            unset($filtered['encryption_key']);
        }

        $settings = $this->settingsRepository->findByScope() ?? new VaultSettings();

        if ($encryptionKey !== null) {
            $settings->setEncryptionKey(is_string($encryptionKey) && $encryptionKey !== '' ? $encryptionKey : null);
        }

        if ($filtered !== []) {
            $settings->mergeValues($filtered);
        }

        $this->settingsRepository->save($settings);
        $this->configProvider->invalidateCache();
    }

    /**
     * Removes all DB overrides; effective config reverts to the YAML baseline.
     */
    public function reset(): void
    {
        if (!$this->databaseEnabled) {
            throw new LogicException('Database config storage is disabled. Enable nowo_vault.config_storage.enabled in YAML.');
        }

        $settings = $this->settingsRepository->findByScope();
        if (!$settings instanceof VaultSettings) {
            $this->configProvider->invalidateCache();

            return;
        }

        $settings->setValues([]);
        $settings->setEncryptionKey(null);
        $this->settingsRepository->save($settings);
        $this->configProvider->invalidateCache();
    }
}
