<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

/**
 * Options for the vault password generator (mode, length and character classes).
 */
final class PasswordGeneratorOptions
{
    /**
     * Creates the options with the given mode, length and character classes.
     */
    public function __construct(
        public string $mode = 'characters',
        public int $length = 20,
        public bool $useLower = true,
        public bool $useUpper = true,
        public bool $useDigits = true,
        public bool $useSymbols = true,
    ) {
    }

    /**
     * Builds options from a loosely typed array, falling back to defaults for missing keys.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: (string) ($data['mode'] ?? 'characters'),
            length: (int) ($data['length'] ?? 20),
            useLower: (bool) ($data['useLower'] ?? true),
            useUpper: (bool) ($data['useUpper'] ?? true),
            useDigits: (bool) ($data['useDigits'] ?? true),
            useSymbols: (bool) ($data['useSymbols'] ?? true),
        );
    }
}
