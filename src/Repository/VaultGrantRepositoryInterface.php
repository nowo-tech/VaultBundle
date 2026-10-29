<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;

/**
 * Persistence contract for vault grants.
 */
interface VaultGrantRepositoryInterface
{
    /**
     * Persists the grant.
     */
    public function save(VaultGrant $grant): void;

    /**
     * Removes the grant.
     */
    public function remove(VaultGrant $grant): void;

    /**
     * Returns the grants on a resource.
     *
     * @return list<VaultGrant>
     */
    public function findByResource(VaultResourceType $type, string $resourceId): array;

    /**
     * Returns the grants given to a grantee.
     *
     * @return list<VaultGrant>
     */
    public function findByGrantee(GranteeType $type, string $granteeId): array;

    /**
     * Finds the grant of a grantee on a resource, or null.
     */
    public function findOne(
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
    ): ?VaultGrant;

    /**
     * Finds a grant by id.
     */
    public function findById(string $id): ?VaultGrant;

    /**
     * Counts grants per resource id for the given resources.
     *
     * @param list<string> $resourceIds
     *
     * @return array<string, int> resource id => grant count
     */
    public function countByResources(VaultResourceType $type, array $resourceIds): array;

    /**
     * Returns the ids of resources granted to the user directly or through the given teams.
     *
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
