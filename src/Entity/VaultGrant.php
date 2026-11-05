<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\ValueObject\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity granting a user or team a permission on a vault item or folder.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_grants')]
#[ORM\UniqueConstraint(name: 'vault_grants_unique', columns: ['resource_type', 'resource_id', 'grantee_type', 'grantee_id'])]
#[ORM\Index(name: 'vault_grants_expires_idx', columns: ['expires_at'])]
#[ORM\Index(name: 'vault_grants_grantee_idx', columns: ['grantee_type', 'grantee_id'])]
class VaultGrant
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Creates a grant of a permission on a resource for a grantee.
     */
    public function __construct(
        #[ORM\Column(name: 'resource_type', type: 'string', length: 16, enumType: VaultResourceType::class)]
        private VaultResourceType $resourceType,
        #[ORM\Column(name: 'resource_id', type: 'string', length: 36)]
        private string $resourceId,
        #[ORM\Column(name: 'grantee_type', type: 'string', length: 16, enumType: GranteeType::class)]
        private GranteeType $granteeType,
        /** User id or team id as string. */
        #[ORM\Column(name: 'grantee_id', type: 'string', length: 128)]
        private string $granteeId,
        #[ORM\Column(type: 'string', length: 16, enumType: VaultPermission::class)]
        private VaultPermission $permission,
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        // 1.15.2: SET NULL (was CASCADE), so deleting the grantor no longer deletes the grants they made.
        #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
        private ?object $createdBy,
        /** Null = permanent; a temporary grant is active while now < expiresAt (1.16.0). */
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $expiresAt = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Returns the grant UUID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the type of the shared resource (item or folder).
     */
    public function getResourceType(): VaultResourceType
    {
        return $this->resourceType;
    }

    /**
     * Returns the id of the shared resource.
     */
    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    /**
     * Returns the grantee type (user or team).
     */
    public function getGranteeType(): GranteeType
    {
        return $this->granteeType;
    }

    /**
     * Returns the grantee id.
     */
    public function getGranteeId(): string
    {
        return $this->granteeId;
    }

    /**
     * Returns the granted permission.
     */
    public function getPermission(): VaultPermission
    {
        return $this->permission;
    }

    /**
     * Changes the granted permission.
     */
    public function setPermission(VaultPermission $permission): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->permission = $permission;

        return $this;
    }

    /**
     * Returns when the grant stops working, or null for a permanent grant (1.16.0).
     */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Sets the expiry (null = permanent) (1.16.0).
     */
    public function setExpiresAt(?DateTimeImmutable $expiresAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Whether the grant has an expiry (1.16.0).
     */
    public function isTemporary(): bool
    {
        return $this->expiresAt instanceof DateTimeImmutable;
    }

    /**
     * Whether the grant works at the given instant: permanent, or `$now < expiresAt` (strict) (1.16.0).
     */
    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return !$this->expiresAt instanceof DateTimeImmutable || $now < $this->expiresAt;
    }

    /**
     * Returns the user that created the grant, or null once that user was deleted (since 1.15.2).
     */
    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
