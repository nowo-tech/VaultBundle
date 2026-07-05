<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmVaultItemRepository::class)]
#[ORM\Table(name: 'vault_items')]
#[ORM\Index(name: 'vault_items_type_idx', columns: ['item_type'])]
#[ORM\Index(name: 'vault_items_deleted_idx', columns: ['deleted_at'])]
class VaultItem
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

    /** @var Collection<int, VaultTag> */
    #[ORM\ManyToMany(targetEntity: VaultTag::class, inversedBy: 'items')]
    #[ORM\JoinTable(name: 'vault_item_tag')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    public function __construct(
        #[ORM\Column(name: 'item_type', type: 'string', length: 32, enumType: VaultItemType::class)]
        private VaultItemType $itemType,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator,
        /** Encrypted JSON payload (libsodium). */
        #[ORM\Column(type: 'text')]
        private string $ciphertext,
        #[ORM\ManyToOne(targetEntity: VaultFolder::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?VaultFolder $folder = null,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
        $this->tags      = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getItemType(): VaultItemType
    {
        return $this->itemType;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title     = $title;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreator(): object
    {
        return $this->creator;
    }

    public function getFolder(): ?VaultFolder
    {
        return $this->folder;
    }

    public function setFolder(?VaultFolder $folder): self
    {
        $this->folder    = $folder;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    public function setCiphertext(string $ciphertext): self
    {
        $this->ciphertext = $ciphertext;
        $this->updatedAt  = new DateTimeImmutable();

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

    /**
     * @return Collection<int, VaultTag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * @param list<VaultTag> $tags
     */
    public function setTags(array $tags): self
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            $this->tags->add($tag);
        }
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getTagNames(): array
    {
        $names = [];
        foreach ($this->tags as $tag) {
            $names[] = $tag->getName();
        }

        return $names === [] ? [''] : $names;
    }
}
