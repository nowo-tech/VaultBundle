<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Service\VaultGrantService;

/**
 * Test fixtures: a permanent grant written as a system change (no actor check, events with a null actor), the way the
 * seeds used the deprecated `VaultGrantService::grant()` / `revoke()` before 2.0 removed them. It runs the service's own
 * upsert / delete paths, so the rows, the change events and the logs are the service's.
 */
final class GrantSeeder
{
    /**
     * Creates the grant, or updates the permission of the existing grant for the same grantee.
     */
    public static function grant(
        VaultGrantService $service,
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        object $createdBy,
    ): VaultGrant {
        /** @var array{0: mixed, 1: VaultGrant} $result */
        $result = (fn (): array => $this->upsert($resourceType, $resourceId, $granteeType, $granteeId, $permission, null, $createdBy, null))->call($service);

        return $result[1];
    }

    /**
     * Deletes the grant without an actor check (reason `manual`, actor null).
     */
    public static function revoke(VaultGrantService $service, VaultGrant $grant): void
    {
        (fn (): bool => $this->delete($grant, null, VaultGrantChangeEvent::REASON_MANUAL))->call($service);
    }
}
