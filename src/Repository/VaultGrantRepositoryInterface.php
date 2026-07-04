<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;

interface VaultGrantRepositoryInterface
{
    public function save(VaultGrant $grant): void;

    public function remove(VaultGrant $grant): void;

    /**
     * @return list<VaultGrant>
     */
    public function findByResource(VaultResourceType $type, string $resourceId): array;

    /**
     * @return list<VaultGrant>
     */
    public function findByGrantee(GranteeType $type, string $granteeId): array;

    public function findOne(
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
    ): ?VaultGrant;

    public function findById(string $id): ?VaultGrant;

    /**
     * @param list<string> $resourceIds
     *
     * @return array<string, int> resource id => grant count
     */
    public function countByResources(VaultResourceType $type, array $resourceIds): array;

    /**
     * @param list<string> $teamIds
     *
     * @return list<string> Resource ids (deduplicated)
     */
    public function findGrantedResourceIds(
        object $user,
        VaultResourceType $resourceType,
        array $teamIds = [],
    ): array;
}
