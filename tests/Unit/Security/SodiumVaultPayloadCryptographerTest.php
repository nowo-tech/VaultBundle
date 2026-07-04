<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SodiumVaultPayloadCryptographerTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $key    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $crypto = new SodiumVaultPayloadCryptographer($key);

        $payload = ['username' => 'demo', 'password' => 's3cret', 'note' => ''];
        $cipher  = $crypto->encrypt($payload);

        self::assertSame($payload, $crypto->decrypt($cipher));
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(RuntimeException::class);
        new SodiumVaultPayloadCryptographer('not-a-valid-key');
    }

    public function testGenerateKeyBase64ProducesValidKey(): void
    {
        $key    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $crypto = new SodiumVaultPayloadCryptographer($key);
        self::assertSame(['a' => 1], $crypto->decrypt($crypto->encrypt(['a' => 1])));
    }

    public function testDecryptRejectsInvalidCiphertext(): void
    {
        $key    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $crypto = new SodiumVaultPayloadCryptographer($key);

        $this->expectException(RuntimeException::class);
        $crypto->decrypt('invalid');
    }

    public function testDecryptRejectsTamperedPayload(): void
    {
        $key      = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $crypto   = new SodiumVaultPayloadCryptographer($key);
        $cipher   = $crypto->encrypt(['secret' => true]);
        $tampered = substr($cipher, 0, -2) . 'xx';

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($tampered);
    }

    public function testDecryptRejectsWrongKey(): void
    {
        $cryptoA = new SodiumVaultPayloadCryptographer(SodiumVaultPayloadCryptographer::generateKeyBase64());
        $cryptoB = new SodiumVaultPayloadCryptographer(SodiumVaultPayloadCryptographer::generateKeyBase64());
        $cipher  = $cryptoA->encrypt(['secret' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vault decryption failed.');
        $cryptoB->decrypt($cipher);
    }
}
