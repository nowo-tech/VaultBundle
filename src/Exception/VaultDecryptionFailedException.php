<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Exception;

use RuntimeException;

/**
 * An item payload could not be decrypted (wrong key, a rotation in progress, a tampered ciphertext) (1.16.0). The
 * message is generic and the exception never chains the cryptographic error, so nothing about the payload leaks.
 */
final class VaultDecryptionFailedException extends RuntimeException
{
    /**
     * Creates the exception with the generic message.
     */
    public function __construct()
    {
        parent::__construct('Vault item could not be decrypted.');
    }
}
