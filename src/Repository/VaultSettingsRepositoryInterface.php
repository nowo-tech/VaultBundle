<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultSettings;

/**
 * Persistence contract for database-backed runtime settings.
 */
interface VaultSettingsRepositoryInterface
{
    /**
     * Finds the settings row of a scope, or null.
     */
    public function findByScope(string $scope = VaultSettings::DEFAULT_SCOPE): ?VaultSettings;

    /**
     * Persists the settings.
     */
    public function save(VaultSettings $settings): void;
}
