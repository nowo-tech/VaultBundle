<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultGrantView;

/**
 * Sharing for a host (2.1.0, DDD-host checklist B1, REQ-ARCH-001 rules 1 and 3): actor as a {@see VaultUserRef},
 * resources and grants by id, {@see VaultGrantView} results. Implemented by {@see VaultGrantCommands} over
 * {@see VaultGrantService::grantAs()} / {@see VaultGrantService::revokeAs()}, so every rule of the share page applies
 * (Share re-checked at the call, no self grant, grantee list, scope membership of a user grantee, temporary-grant
 * rules, GRANT_POLICY_CHECK).
 *
 * Errors: {@see VaultAccessDeniedException} for an unknown or refused actor; {@see VaultGrantRefusedException} (with
 * its reason code) when a sharing rule refuses the call.
 */
interface VaultGrantCommandsInterface
{
    /**
     * Grants (or updates) access to an item or folder. The view's `result` tells whether a grant was created, updated
     * or already covered by a permanent grant (then the view is that grant).
     */
    public function grant(
        VaultUserRef $actor,
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
        VaultPermission $permission,
        ?DateTimeImmutable $expiresAt = null,
    ): VaultGrantView;

    /**
     * Revokes a grant (the actor needs Share on its resource). False when the grant does not exist (any more).
     */
    public function revoke(VaultUserRef $actor, string $grantId): bool;

    /**
     * The grants of an active item or folder, expired ones not yet purged included (the actor needs Share on it).
     *
     * @return list<VaultGrantView>
     */
    public function listForResource(VaultUserRef $actor, VaultResourceType $resourceType, string $resourceId): array;
}
