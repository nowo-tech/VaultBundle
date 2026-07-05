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

    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

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
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        if (!$this->databaseEnabled) {
            $this->resolved = $this->validate($this->yamlBaseline);

            return $this->resolved;
        }

        $this->resolved = $this->cache->get(self::CACHE_KEY, fn (ItemInterface $item): array => $this->validate($this->loadMergedFromDatabase()));

        return $this->resolved;
    }

    public function invalidateCache(): void
    {
        $this->resolved = null;
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
