<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultGrantRepository;
use Nowo\VaultBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmVaultGrantRepository::class)]
#[ORM\Table(name: 'vault_grants')]
#[ORM\UniqueConstraint(name: 'vault_grants_unique', columns: ['resource_type', 'resource_id', 'grantee_type', 'grantee_id'])]
class VaultGrant
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

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
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(name: 'created_by_id', nullable: false, onDelete: 'CASCADE')]
        private object $createdBy,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getResourceType(): VaultResourceType
    {
        return $this->resourceType;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getGranteeType(): GranteeType
    {
        return $this->granteeType;
    }

    public function getGranteeId(): string
    {
        return $this->granteeId;
    }

    public function getPermission(): VaultPermission
    {
        return $this->permission;
    }

    public function setPermission(VaultPermission $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function getCreatedBy(): object
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
