<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;

final readonly class VaultGrantService
{
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
    ) {
    }

    public function grant(
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        object $createdBy,
    ): VaultGrant {
        $existing = $this->grantRepository->findOne($resourceType, $resourceId, $granteeType, $granteeId);
        if ($existing instanceof VaultGrant) {
            $existing->setPermission($permission);
            $this->grantRepository->save($existing);

            return $existing;
        }

        $grant = new VaultGrant($resourceType, $resourceId, $granteeType, $granteeId, $permission, $createdBy);
        $this->grantRepository->save($grant);

        return $grant;
    }

    public function revoke(VaultGrant $grant): void
    {
        $this->grantRepository->remove($grant);
    }

    /**
     * @return list<VaultGrant>
     */
    public function listForResource(VaultResourceType $type, string $resourceId): array
    {
        return $this->grantRepository->findByResource($type, $resourceId);
    }

    /**
     * @param list<string> $resourceIds
     *
     * @return array<string, int>
     */
    public function countForResources(VaultResourceType $type, array $resourceIds): array
    {
        return $this->grantRepository->countByResources($type, $resourceIds);
    }

    public function findById(string $id): ?VaultGrant
    {
        return $this->grantRepository->findById($id);
    }
}
