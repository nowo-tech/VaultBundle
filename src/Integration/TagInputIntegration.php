<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Integration;

/**
 * Detects whether TagInputBundle is installed at runtime.
 */
final class TagInputIntegration
{
    public const TAG_INPUT_TYPE = 'Nowo\\TagInputBundle\\Form\\TagType';

    public static function isAvailable(): bool
    {
        return class_exists(self::TAG_INPUT_TYPE);
    }
}
