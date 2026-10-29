<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Config;

use InvalidArgumentException;
use LogicException;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Integration\DoctrineEncryptIntegration;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function is_string;

/**
 * Persists runtime configuration overrides to the database and refreshes the cache.
 */
final readonly class VaultRuntimeConfigWriter
{
    private bool $encryptionKeyStorageAvailable;

    /**
     * Creates the writer; encryption key storage availability defaults to DoctrineEncrypt detection.
     *
     * @param bool|null $encryptionKeyStorageAvailable whether vault_settings.encryption_key is encrypted at rest; null detects DoctrineEncryptBundle
     */
    public function __construct(
        private bool $databaseEnabled,
        private VaultSettingsRepositoryInterface $settingsRepository,
        private VaultRuntimeConfigProvider $configProvider,
        ?bool $encryptionKeyStorageAvailable = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->encryptionKeyStorageAvailable = $encryptionKeyStorageAvailable ?? DoctrineEncryptIntegration::isAvailable();
    }

    /**
     * True when the master key can be stored in vault_settings: the #[Encrypted] column
     * is only encrypted at rest when DoctrineEncryptBundle is installed.
     */
    public function canStoreEncryptionKey(): bool
    {
        return $this->databaseEnabled && $this->encryptionKeyStorageAvailable;
    }

    /**
     * Throws when the key is not a base64-encoded 32-byte libsodium key.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValidEncryptionKey(string $encryptionKeyBase64): void
    {
        try {
            new SodiumVaultPayloadCryptographer($encryptionKeyBase64);
        } catch (RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Persists the allowed runtime configuration values to the database and invalidates the cache.
     *
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

        if (is_string($encryptionKey) && $encryptionKey !== '') {
            if (!$this->encryptionKeyStorageAvailable) {
                throw new LogicException('Storing the vault encryption key in the database requires nowo-tech/doctrine-encrypt-bundle (encryption at rest). Configure nowo_vault.encryption_key via YAML/env instead.');
            }

            self::assertValidEncryptionKey($encryptionKey);
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

        // Only the changed top-level keys are logged, never their values (the encryption key above all; REQ-OBS-001).
        ($this->logger ?? new NullLogger())->notice('Vault runtime configuration updated.', [
            'bundle'                => 'nowo_vault',
            'action'                => 'runtime_config_update',
            'keys'                  => array_keys($filtered),
            'encryption_key_change' => $encryptionKey !== null,
        ]);
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
        ($this->logger ?? new NullLogger())->notice('Vault runtime configuration reset to the YAML baseline.', ['bundle' => 'nowo_vault', 'action' => 'runtime_config_reset']);
    }
}
