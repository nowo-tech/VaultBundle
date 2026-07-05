<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Integration;

/**
 * Detects whether DoctrineEncryptBundle is installed at runtime.
 */
final class DoctrineEncryptIntegration
{
    public const ENCRYPTED_ATTRIBUTE = \Nowo\DoctrineEncryptBundle\Configuration\Encrypted::class;

    public const ENCRYPT_UTIL = 'Nowo\\DoctrineEncryptBundle\\Util\\EncryptUtil';

    public static function isAvailable(): bool
    {
        return class_exists(self::ENCRYPTED_ATTRIBUTE);
    }
}
