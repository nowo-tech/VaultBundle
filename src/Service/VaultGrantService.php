<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creates, updates, lists and revokes sharing grants.
 */
final readonly class VaultGrantService
{
    /**
     * Stores the grant repository and the optional logger.
     */
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Creates a grant, or updates the permission of the existing grant for the same grantee.
     */
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
            $this->logGrant('Vault grant updated.', 'grant_update', $existing);

            return $existing;
        }

        $grant = new VaultGrant($resourceType, $resourceId, $granteeType, $granteeId, $permission, $createdBy);
        $this->grantRepository->save($grant);
        $this->logGrant('Vault grant created.', 'grant_create', $grant);

        return $grant;
    }

    /**
     * Deletes a grant.
     */
    public function revoke(VaultGrant $grant): void
    {
        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $this->grantRepository->remove($grant);
        $this->logGrant('Vault grant revoked.', 'grant_revoke', $grant);
    }

    /**
     * Returns the grants of one resource.
     *
     * @return list<VaultGrant>
     */
    public function listForResource(VaultResourceType $type, string $resourceId): array
    {
        return $this->grantRepository->findByResource($type, $resourceId);
    }

    /**
     * Returns the grant count per resource id.
     *
     * @param list<string> $resourceIds
     *
     * @return array<string, int>
     */
    public function countForResources(VaultResourceType $type, array $resourceIds): array
    {
        return $this->grantRepository->countByResources($type, $resourceIds);
    }

    /**
     * Returns the grant with the given id, or null.
     */
    public function findById(string $id): ?VaultGrant
    {
        return $this->grantRepository->findById($id);
    }

    /**
     * Logs a sharing change with identifiers only (REQ-OBS-001): no item titles or payload data.
     */
    private function logGrant(string $message, string $action, VaultGrant $grant): void
    {
        ($this->logger ?? new NullLogger())->info($message, [
            'bundle'        => 'nowo_vault',
            'action'        => $action,
            'resource_type' => $grant->getResourceType()->value,
            'resource_id'   => $grant->getResourceId(),
            'grantee_type'  => $grant->getGranteeType()->value,
            'grantee_id'    => $grant->getGranteeId(),
            'permission'    => $grant->getPermission()->value,
        ]);
    }
}
