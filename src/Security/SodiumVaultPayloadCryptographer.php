<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use RuntimeException;

use function base64_decode;
use function base64_encode;
use function json_decode;
use function json_encode;
use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_keygen;
use function sodium_crypto_secretbox_open;
use function strlen;

use const JSON_THROW_ON_ERROR;
use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

/**
 * Encrypts vault item payloads with libsodium secretbox (server-side master key).
 */
final readonly class SodiumVaultPayloadCryptographer implements VaultPayloadCryptographerInterface
{
    private string $key;

    public function __construct(string $encryptionKeyBase64)
    {
        $decoded = base64_decode($encryptionKeyBase64, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('nowo_vault.encryption_key must be a base64-encoded 32-byte libsodium key.');
        }

        $this->key = $decoded;
    }

    public static function generateKeyBase64(): string
    {
        $key = sodium_crypto_secretbox_keygen();

        return base64_encode($key);
    }

    public function encrypt(array $payload): string
    {
        $json   = json_encode($payload, JSON_THROW_ON_ERROR);
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($json, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $ciphertext): array
    {
        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
            throw new RuntimeException('Invalid vault ciphertext.');
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $json = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        if ($json === false) {
            throw new RuntimeException('Vault decryption failed.');
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
