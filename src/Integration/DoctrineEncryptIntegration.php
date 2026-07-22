<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Integration;

use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Nowo\DoctrineEncryptBundle\Util\EncryptUtil;

/**
 * Detects whether DoctrineEncryptBundle is installed at runtime.
 */
final class DoctrineEncryptIntegration
{
    public const ENCRYPTED_ATTRIBUTE = Encrypted::class;

    public const ENCRYPT_UTIL = EncryptUtil::class;

    public static function isAvailable(): bool
    {
        return class_exists(self::ENCRYPTED_ATTRIBUTE);
    }
}
