<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultSettings;

interface VaultSettingsRepositoryInterface
{
    public function findByScope(string $scope = VaultSettings::DEFAULT_SCOPE): ?VaultSettings;

    public function save(VaultSettings $settings): void;
}
