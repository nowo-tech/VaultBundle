<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\ValueObject\Uuid;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity for a vault folder owned by its creator (soft-deletable, optionally nested).
 *
 * Since 1.16.0 a folder is owned by a user (`creator_id`) or by a host scope (`owner_scope`), exactly one of them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_folders')]
#[ORM\Index(name: 'vault_folders_owner_scope_idx', columns: ['owner_scope', 'deleted_at'])]
#[ORM\HasLifecycleCallbacks]
class VaultFolder
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'deleted_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /** Host scope key of a scope folder, null for a personal folder (1.16.0). */
    #[ORM\Column(name: 'owner_scope', type: 'string', length: 128, nullable: true)]
    private ?string $ownerScope;

    /** Who created the folder (audit only); null once that user was deleted (1.16.0). */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?object $createdBy;

    /**
     * Creates a folder for the given creator, optionally under a parent folder. A scope folder passes `$creator = null`
     * and `$ownerScope` (use {@see self::forScope()}).
     *
     * @throws VaultOwnerException when the owner is ambiguous or the scope key is invalid
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        private string $name,
        /** Personal owner; null for a scope folder (1.16.0). */
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?object $creator,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?self $parent = null,
        ?string $ownerScope = null,
        ?object $createdBy = null,
    ) {
        $this->ownerScope = VaultOwner::fromColumns($creator, $ownerScope)->getScope();
        $this->createdBy  = $createdBy ?? $creator;
        $this->id         = Uuid::generate()->toString();
        $this->createdAt  = new DateTimeImmutable();
        $this->updatedAt  = new DateTimeImmutable();
    }

    /**
     * Creates a scope-owned folder (1.16.0).
     *
     * @throws VaultOwnerException when the scope key is invalid
     */
    public static function forScope(string $name, string $scope, ?object $createdBy, ?self $parent = null): self
    {
        return new self($name, null, $parent, $scope, $createdBy);
    }

    /**
     * Returns the folder UUID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the folder name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Renames the folder and touches its update timestamp.
     */
    public function setName(string $name): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->name = $name;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns the user that owns the personal folder.
     *
     * @deprecated since 1.16.0, use getOwner() (or getCreatedBy() for the audit creator)
     *
     * @throws VaultOwnerException for a scope folder
     */
    public function getCreator(): object
    {
        if ($this->creator === null) {
            throw new VaultOwnerException('A scope folder has no personal owner; use getOwner().');
        }

        return $this->creator;
    }

    /**
     * The owner: a user or a scope (1.16.0).
     */
    public function getOwner(): VaultOwner
    {
        return VaultOwner::fromColumns($this->creator, $this->ownerScope);
    }

    /**
     * The owning scope key, or null for a personal folder (1.16.0).
     */
    public function getOwnerScope(): ?string
    {
        return $this->ownerScope;
    }

    /**
     * Who created the folder (audit only), or null (1.16.0).
     */
    public function getCreatedBy(): ?object
    {
        return $this->createdBy;
    }

    /**
     * Returns the parent folder, or null for a top-level folder.
     */
    public function getParent(): ?self
    {
        return $this->parent;
    }

    /**
     * Sets the parent folder and touches the update timestamp.
     */
    public function setParent(?self $parent): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->parent = $parent;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns when the folder was moved to the trash, or null when active.
     */
    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Whether the folder is in the trash.
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    /**
     * Moves the folder to the trash (soft delete).
     */
    public function markDeleted(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->deletedAt = new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Restores the folder from the trash.
     */
    public function restore(): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->deletedAt = null;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last update timestamp.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
