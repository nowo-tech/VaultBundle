<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultFolderRepository;
use Nowo\VaultBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmVaultFolderRepository::class)]
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

    public function __construct(#[ORM\Column(type: 'string', length: 255)]
        private string $name, #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator, #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?self $parent = null)
    {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name      = $name;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreator(): object
    {
        return $this->creator;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent    = $parent;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    public function markDeleted(): self
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function restore(): self
    {
        $this->deletedAt = null;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
