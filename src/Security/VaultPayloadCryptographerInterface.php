<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

/**
 * Encrypts and decrypts vault item payloads.
 */
interface VaultPayloadCryptographerInterface
{
    /**
     * Encrypts a payload into an opaque ciphertext string.
     *
     * @param array<string, mixed> $payload
     */
    public function encrypt(array $payload): string;

    /**
     * Decrypts a ciphertext back into the payload.
     *
     * @return array<string, mixed>
     */
    public function decrypt(string $ciphertext): array;
}
