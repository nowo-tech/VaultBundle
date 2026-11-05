<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A grant was created, updated, revoked or expired (1.16.0: GRANT_CREATED, GRANT_UPDATED, GRANT_REVOKED,
 * GRANT_EXPIRED). Ids and permissions only: no entity, no title, no secret. Dispatched after the change was flushed.
 */
final class VaultGrantChangeEvent extends Event
{
    public const REASON_MANUAL          = 'manual';
    public const REASON_ERASURE         = 'erasure';
    public const REASON_RESOURCE_PURGED = 'resource_purged';
    public const REASON_EXPIRED         = 'expired';

    /**
     * @param string|null $ownerKey {@see VaultOwner::key()} of the shared resource, null when it no longer exists
     * @param string|null $actorId user id of the actor, null for a system change (purge, erasure, deprecated API)
     * @param string|null $reason revoke reason (REASON_*), null for created / updated
     */
    public function __construct(
        private readonly string $grantId,
        private readonly VaultResourceType $resourceType,
        private readonly string $resourceId,
        private readonly ?string $ownerKey,
        private readonly GranteeType $granteeType,
        private readonly string $granteeId,
        private readonly VaultPermission $permission,
        private readonly ?DateTimeImmutable $expiresAt,
        private readonly ?string $actorId,
        private readonly DateTimeImmutable $occurredAt,
        private readonly ?VaultPermission $previousPermission = null,
        private readonly ?DateTimeImmutable $previousExpiresAt = null,
        private readonly ?string $reason = null,
    ) {
    }

    /**
     * Grant id.
     */
    public function getGrantId(): string
    {
        return $this->grantId;
    }

    /**
     * Shared resource type.
     */
    public function getResourceType(): VaultResourceType
    {
        return $this->resourceType;
    }

    /**
     * Shared resource id.
     */
    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    /**
     * Owner key of the shared resource (`user:<id>` / `scope:<key>`), or null when it no longer exists.
     */
    public function getOwnerKey(): ?string
    {
        return $this->ownerKey;
    }

    /**
     * Grantee type.
     */
    public function getGranteeType(): GranteeType
    {
        return $this->granteeType;
    }

    /**
     * Grantee id.
     */
    public function getGranteeId(): string
    {
        return $this->granteeId;
    }

    /**
     * Permission after the change (for a revoke / expiry: the permission the grant had).
     */
    public function getPermission(): VaultPermission
    {
        return $this->permission;
    }

    /**
     * Expiry after the change (null = permanent).
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Actor user id, or null for a system change.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * When the change happened (injected clock).
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Permission before an update, else null.
     */
    public function getPreviousPermission(): ?VaultPermission
    {
        return $this->previousPermission;
    }

    /**
     * Expiry before an update, else null.
     */
    public function getPreviousExpiresAt(): ?DateTimeImmutable
    {
        return $this->previousExpiresAt;
    }

    /**
     * Revoke reason (REASON_*), or null.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
