<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Config;

use Nowo\VaultBundle\DependencyInjection\RuntimeConfiguration;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function is_array;
use function is_string;

/**
 * Merged runtime configuration: YAML baseline + optional DB overrides (cached).
 */
final class VaultRuntimeConfigProvider
{
    public const CACHE_KEY = 'nowo_vault.runtime_config.merged.v2';

    /**
     * Validated YAML baseline; immutable for the container lifetime, so it is safe to keep across requests.
     *
     * @var array<string, mixed>|null
     */
    private ?array $validatedYamlBaseline = null;

    /**
     * @param array<string, mixed> $yamlBaseline
     */
    public function __construct(
        private readonly array $yamlBaseline,
        private readonly bool $databaseEnabled,
        private readonly VaultSettingsRepositoryInterface $settingsRepository,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Database-backed config is read from the shared cache pool on every call (never memoized in the
     * service), so a change made by another worker or the CLI is visible on the next call.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if (!$this->databaseEnabled) {
            // @igor-ignore - Not shared worker service state.
            return $this->validatedYamlBaseline ??= $this->validate($this->yamlBaseline);
        }

        return $this->cache->get(self::CACHE_KEY, fn (ItemInterface $item): array => $this->validate($this->loadMergedFromDatabase()));
    }

    public function invalidateCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMergedFromDatabase(): array
    {
        $merged = $this->yamlBaseline;
        $stored = $this->settingsRepository->findByScope();

        if ($stored instanceof VaultSettings) {
            if ($stored->getValues() !== []) {
                $merged = array_replace_recursive($merged, $stored->getValues());
            }

            $encryptionKey = $stored->getEncryptionKey();
            if (is_string($encryptionKey) && $encryptionKey !== '') {
                $merged['encryption_key'] = $encryptionKey;
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function validate(array $config): array
    {
        $defaults         = (new Processor())->processConfiguration(new RuntimeConfiguration(), [[]]);
        $config['routes'] = array_replace_recursive(
            $defaults['routes'],
            is_array($config['routes'] ?? null) ? $config['routes'] : [],
        );

        return (new Processor())->processConfiguration(new RuntimeConfiguration(), [$config]);
    }
}
