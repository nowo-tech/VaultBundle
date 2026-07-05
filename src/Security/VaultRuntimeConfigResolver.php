<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use RuntimeException;

use function is_string;

/**
 * Resolves the effective vault encryption key from YAML baseline and optional DB overrides.
 */
final readonly class VaultRuntimeConfigResolver
{
    /**
     * @param array<string, mixed> $yamlBaseline
     */
    public function __construct(
        private VaultRuntimeConfigProvider $runtimeConfig,
        private array $yamlBaseline,
        private bool $databaseEnabled,
    ) {
    }

    public function resolveEncryptionKeyBase64(): string
    {
        if ($this->databaseEnabled) {
            $merged = $this->runtimeConfig->get();
            $key    = $merged['encryption_key'] ?? null;
            if (is_string($key) && $key !== '') {
                return $key;
            }
        }

        $bootstrap = $this->yamlBaseline['encryption_key'] ?? null;
        if (is_string($bootstrap) && $bootstrap !== '') {
            return $bootstrap;
        }

        throw new RuntimeException('nowo_vault.encryption_key is not configured in YAML or database.');
    }
}
