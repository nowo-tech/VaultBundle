<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use LogicException;
use SensitiveParameter;

/**
 * Holder of a decrypted value on its way to the response (1.16.0). It hides the value from dumps (`var_dump`,
 * `print_r`, the profiler's dumper) and refuses serialization, so it cannot end up in a cache, a session or a log
 * context by accident.
 */
final readonly class VaultRevealedValue
{
    /**
     * @param list<string>|string $value
     */
    public function __construct(
        #[SensitiveParameter]
        private string|array $value,
    ) {
    }

    /**
     * The value (a string, or a list for `websites`).
     *
     * @return list<string>|string
     */
    public function get(): string|array
    {
        return $this->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('A revealed vault value cannot be serialized.');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A revealed vault value cannot be unserialized.');
    }
}
