<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Dto\PasswordGeneratorOptions;

use function count;
use function strlen;

/**
 * Secure password and passphrase generator (server-side; also used by the manage UI API).
 */
final class VaultPasswordGenerator
{
    private const LOWER   = 'abcdefghijklmnopqrstuvwxyz';
    private const UPPER   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DIGITS  = '0123456789';
    private const SYMBOLS = '!@#$%^&*()-_=+[]{};:,.?';

    private const WORDS = [
        'apple', 'river', 'cloud', 'stone', 'light', 'forest', 'ocean', 'mountain',
        'silver', 'golden', 'brave', 'quiet', 'swift', 'bright', 'gentle', 'solid',
        'north', 'south', 'east', 'west', 'spring', 'summer', 'winter', 'autumn',
    ];

    public function generate(PasswordGeneratorOptions $options): string
    {
        if ($options->mode === 'words') {
            return $this->generatePassphrase($options);
        }

        return $this->generateCharacters($options);
    }

    private function generateCharacters(PasswordGeneratorOptions $options): string
    {
        $pool = '';
        if ($options->useLower) {
            $pool .= self::LOWER;
        }
        if ($options->useUpper) {
            $pool .= self::UPPER;
        }
        if ($options->useDigits) {
            $pool .= self::DIGITS;
        }
        if ($options->useSymbols) {
            $pool .= self::SYMBOLS;
        }

        if ($pool === '') {
            $pool = self::LOWER . self::UPPER . self::DIGITS;
        }

        $length = max(4, min(128, $options->length));
        $chars  = [];
        $max    = strlen($pool) - 1;

        for ($i = 0; $i < $length; ++$i) {
            $chars[] = $pool[random_int(0, $max)];
        }

        return implode('', $chars);
    }

    private function generatePassphrase(PasswordGeneratorOptions $options): string
    {
        $count  = max(3, min(12, (int) ($options->length / 4)));
        $words  = [];
        $maxIdx = count(self::WORDS) - 1;

        for ($i = 0; $i < $count; ++$i) {
            $word = self::WORDS[random_int(0, $maxIdx)];
            if ($options->useUpper && $i === 0) {
                $word = ucfirst($word);
            }
            $words[] = $word;
        }

        $sep = $options->useSymbols ? '-' : ' ';

        return implode($sep, $words);
    }

    public function estimateStrength(string $password): string
    {
        $len = strlen($password);
        if ($len >= 16) {
            return 'strong';
        }
        if ($len >= 10) {
            return 'medium';
        }

        return 'weak';
    }
}
