<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\ValueObject\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity for a vault folder owned by its creator (soft-deletable, optionally nested).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_folders')]
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

    /**
     * Creates a folder for the given creator, optionally under a parent folder.
     */
    public function __construct(#[ORM\Column(type: 'string', length: 255)]
        private string $name, #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator, #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?self $parent = null)
    {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
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
     * Returns the user that created (owns) the folder.
     */
    public function getCreator(): object
    {
        return $this->creator;
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
