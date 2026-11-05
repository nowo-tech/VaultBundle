<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultTag;

/**
 * Scope tag queries (1.16.0). Implemented by the Doctrine tag repository.
 */
interface VaultScopeTagRepositoryInterface
{
    /**
     * The scope's tags, by name.
     *
     * @return list<VaultTag>
     */
    public function findByScope(string $scope): array;

    /**
     * The scope tag with this name, or null.
     */
    public function findOneByScopeAndName(string $scope, string $name): ?VaultTag;
}
