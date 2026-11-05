<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use function in_array;
use function mb_substr;
use function preg_replace;
use function rawurlencode;
use function strrchr;
use function substr;
use function trim;

/**
 * File name of a downloaded attachment (1.16.0): the stored client name reduced to a safe basename (no path, no
 * control characters, no `"`, `\` or `/`, at most 100 characters, `attachment.bin` when nothing is left).
 */
final class VaultAttachmentFilename
{
    public const FALLBACK   = 'attachment.bin';
    public const MAX_LENGTH = 100;

    /**
     * The sanitised basename.
     */
    public static function sanitize(string $name): string
    {
        foreach (['/', '\\'] as $separator) {
            $tail = strrchr($name, $separator);
            if ($tail !== false) {
                $name = substr($tail, 1);
            }
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', $name), " .\t");
        $name = mb_substr($name, 0, self::MAX_LENGTH);

        return in_array($name, ['', '.', '..'], true) ? self::FALLBACK : $name;
    }

    /**
     * `attachment; filename="<ascii>"; filename*=UTF-8''<rfc5987>` for the sanitised name.
     */
    public static function contentDisposition(string $name): string
    {
        $safe  = self::sanitize($name);
        $ascii = (string) preg_replace('/[^\x20-\x7E]/u', '_', $safe);

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($safe);
    }
}
