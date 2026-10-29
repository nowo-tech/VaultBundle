<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

/**
 * Resolves the libsodium master key from merged runtime config (YAML bootstrap + optional DB override).
 */
final class RuntimeKeyVaultPayloadCryptographer implements VaultPayloadCryptographerInterface
{
    private ?SodiumVaultPayloadCryptographer $delegate = null;

    private ?string $activeKeyBase64 = null;

    /**
     * Creates the cryptographer that resolves the active encryption key at runtime.
     */
    public function __construct(
        private readonly VaultRuntimeConfigResolver $configResolver,
    ) {
    }

    /**
     * Encrypts the payload with the currently configured key.
     */
    public function encrypt(array $payload): string
    {
        return $this->delegate()->encrypt($payload);
    }

    /**
     * Decrypts the ciphertext with the currently configured key.
     */
    public function decrypt(string $ciphertext): array
    {
        return $this->delegate()->decrypt($ciphertext);
    }

    private function delegate(): SodiumVaultPayloadCryptographer
    {
        $keyBase64 = $this->configResolver->resolveEncryptionKeyBase64();
        if (!$this->delegate instanceof SodiumVaultPayloadCryptographer || $this->activeKeyBase64 !== $keyBase64) {
            // @igor-ignore - Security gate reads config/credentials per call; no cross-request cache.
            $this->activeKeyBase64 = $keyBase64;
            // @igor-ignore - Security gate reads config/credentials per call; no cross-request cache.
            $this->delegate = new SodiumVaultPayloadCryptographer($keyBase64);
        }

        return $this->delegate;
    }
}
