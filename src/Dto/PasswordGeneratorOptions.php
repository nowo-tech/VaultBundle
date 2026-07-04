<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Dto;

final class PasswordGeneratorOptions
{
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
