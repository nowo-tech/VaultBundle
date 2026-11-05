<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultFolder;

/**
 * Scope-owned folder queries (1.16.0). Implemented by the Doctrine folder repository.
 */
interface VaultScopeFolderRepositoryInterface
{
    /**
     * The scope's active folders, by name.
     *
     * @return list<VaultFolder>
     */
    public function findByScope(string $scope): array;
}
