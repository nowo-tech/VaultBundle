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
     * Creates the resolver from the runtime configuration provider and the YAML baseline.
     *
     * @param array<string, mixed> $yamlBaseline
     */
    public function __construct(
        private VaultRuntimeConfigProvider $runtimeConfig,
        private array $yamlBaseline,
        private bool $databaseEnabled,
    ) {
    }

    /**
     * True when nowo_vault.encryption_key is set in YAML/env (it may already encrypt items).
     */
    public function hasYamlEncryptionKey(): bool
    {
        $bootstrap = $this->yamlBaseline['encryption_key'] ?? null;

        return is_string($bootstrap) && $bootstrap !== '';
    }

    /**
     * True when an effective key already exists (YAML/env or database). Replacing it
     * without re-encrypting items makes every stored item undecryptable.
     */
    public function hasConfiguredEncryptionKey(): bool
    {
        if ($this->hasYamlEncryptionKey()) {
            return true;
        }

        if (!$this->databaseEnabled) {
            return false;
        }

        $key = $this->runtimeConfig->get()['encryption_key'] ?? null;

        return is_string($key) && $key !== '';
    }

    /**
     * Returns the active base64 encryption key (database value when enabled and set, otherwise the YAML key).
     */
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
