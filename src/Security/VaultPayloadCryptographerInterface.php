<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

interface VaultPayloadCryptographerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encrypt(array $payload): string;

    /**
     * @return array<string, mixed>
     */
    public function decrypt(string $ciphertext): array;
}
