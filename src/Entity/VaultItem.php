<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\ValueObject\Uuid;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity for a vault item whose secret payload is stored encrypted (ciphertext).
 *
 * Since 1.16.0 an item is owned either by a user (the personal vault: `creator_id` set, `owner_scope` null) or by a
 * host scope (`owner_scope` set, `creator_id` null); `created_by_id` records who created it (audit only).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_items')]
#[ORM\Index(name: 'vault_items_type_idx', columns: ['item_type'])]
#[ORM\Index(name: 'vault_items_deleted_idx', columns: ['deleted_at'])]
#[ORM\Index(name: 'vault_items_owner_scope_idx', columns: ['owner_scope', 'deleted_at', 'updated_at'])]
#[ORM\Index(name: 'vault_items_created_by_idx', columns: ['created_by_id'])]
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

    /** Last change of a secret field (1.16.0); not touched by rotation, title, tag or folder changes. */
    #[ORM\Column(name: 'secret_changed_at', type: 'datetime_immutable', nullable: true)]
    // @phpstan-ignore property.unusedType (NULL on rows written before the 1.16.0 backfill)
    private ?DateTimeImmutable $secretChangedAt;

    /** Scope items only: kept out of the metadata catalogue (1.16.0, Q-O4). */
    #[ORM\Column(name: 'catalogue_hidden', type: 'boolean', options: ['default' => false])]
    private bool $catalogueHidden = false;

    /** Host scope key of a scope item, null for a personal item (1.16.0). */
    #[ORM\Column(name: 'owner_scope', type: 'string', length: 128, nullable: true)]
    private ?string $ownerScope;

    /** Who created the item (audit only, never used for access); null once that user was deleted (1.16.0). */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private object|string|null $createdBy;

    /** @var Collection<int, VaultTag> */
    #[ORM\ManyToMany(targetEntity: VaultTag::class, inversedBy: 'items')]
    #[ORM\JoinTable(name: 'vault_item_tag')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $tags;

    /**
     * Creates an item of the given type with its title, creator, ciphertext and optional folder.
     *
     * A personal item passes its owner as `$creator`. A scope item passes `$creator = null` and `$ownerScope` (use
     * {@see self::forScope()}). Exactly one of the two must be set.
     *
     * @throws VaultOwnerException when the owner is ambiguous or the scope key is invalid
     */
    public function __construct(
        #[ORM\Column(name: 'item_type', type: 'string', length: 32, enumType: VaultItemType::class)]
        private VaultItemType $itemType,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        /** Personal owner; null for a scope item (1.16.0). Still ON DELETE CASCADE (safety net). */
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private object|string|null $creator,
        /** Encrypted JSON payload (libsodium). */
        #[ORM\Column(type: 'text')]
        private string $ciphertext,
        #[ORM\ManyToOne(targetEntity: VaultFolder::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?VaultFolder $folder = null,
        ?string $ownerScope = null,
        object|string|null $createdBy = null,
    ) {
        // The folder's owner is checked by the services that create / move items (VaultItemCreator, the controllers):
        // rows written before 1.16.0 may hold a folder of another user, and the guard ignores such folder grants.
        $owner = VaultOwner::fromColumns(VaultUserId::wrap($creator), $ownerScope);

        $this->ownerScope      = $owner->getScope();
        $this->createdBy       = $createdBy ?? $creator;
        $this->id              = Uuid::generate()->toString();
        $this->createdAt       = new DateTimeImmutable();
        $this->updatedAt       = new DateTimeImmutable();
        $this->secretChangedAt = $this->createdAt;
        $this->tags            = new ArrayCollection();
    }

    /**
     * Creates a scope-owned item (1.16.0). The scope key comes from the route, never from form data.
     *
     * @throws VaultOwnerException when the scope key is invalid
     */
    public static function forScope(
        VaultItemType $itemType,
        string $title,
        string $scope,
        object|string|null $createdBy,
        string $ciphertext,
        ?VaultFolder $folder = null,
    ): self {
        return new self($itemType, $title, null, $ciphertext, $folder, $scope, $createdBy);
    }

    /**
     * Returns the item UUID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the item type.
     */
    public function getItemType(): VaultItemType
    {
        return $this->itemType;
    }

    /**
     * Returns the item title (stored in plaintext).
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Sets the title and touches the update timestamp.
     */
    public function setTitle(string $title): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->title = $title;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns the user that owns the personal item.
     *
     * @deprecated since 1.16.0, use getOwner() (or getCreatedBy() for the audit creator)
     *
     * @throws VaultOwnerException for a scope item (it has no personal owner)
     */
    public function getCreator(): object
    {
        if ($this->creator === null) {
            throw new VaultOwnerException('A scope item has no personal owner; use getOwner().');
        }

        return VaultUserId::wrap($this->creator);
    }

    /**
     * The owner: the user of a personal item, or the scope of a scope item (1.16.0).
     */
    public function getOwner(): VaultOwner
    {
        return VaultOwner::fromColumns(VaultUserId::wrap($this->creator), $this->ownerScope);
    }

    /**
     * Whether the item belongs to a personal vault (1.16.0).
     */
    public function isPersonal(): bool
    {
        return $this->ownerScope === null;
    }

    /**
     * The owning scope key, or null for a personal item (1.16.0).
     */
    public function getOwnerScope(): ?string
    {
        return $this->ownerScope;
    }

    /**
     * Who created the item (audit only), or null once that user was deleted (1.16.0).
     */
    public function getCreatedBy(): ?object
    {
        return VaultUserId::wrap($this->createdBy);
    }

    /**
     * The id of the personal owner, or null for a scope item (1.17.0). Works with both user mappings.
     */
    public function getCreatorId(): ?string
    {
        return VaultUserId::idOf($this->creator);
    }

    /**
     * The id of who created the item (audit only), or null (1.17.0). Works with both user mappings.
     */
    public function getCreatedById(): ?string
    {
        return VaultUserId::idOf($this->createdBy);
    }

    /**
     * Last change of a secret field (1.16.0); null only for rows created before the backfill.
     */
    public function getSecretChangedAt(): ?DateTimeImmutable
    {
        return $this->secretChangedAt;
    }

    /**
     * Records a change of a secret field (password, card number, note, ...) at the given instant (1.16.0).
     */
    public function markSecretChanged(DateTimeImmutable $at): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->secretChangedAt = $at;

        return $this;
    }

    /**
     * Whether a scope item is hidden from the catalogue (1.16.0, Q-O4).
     */
    public function isCatalogueHidden(): bool
    {
        return $this->catalogueHidden;
    }

    /**
     * Hides or shows a scope item in the catalogue (1.16.0). Has no effect on access.
     */
    public function setCatalogueHidden(bool $hidden): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->catalogueHidden = $hidden;

        return $this;
    }

    /**
     * Replaces the ciphertext during a key rotation (1.16.0): the payload is unchanged, so neither `updated_at` nor
     * `secret_changed_at` is touched (rotation must not reorder lists or reset password ages).
     */
    public function replaceCiphertextForRotation(string $ciphertext): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->ciphertext = $ciphertext;

        return $this;
    }

    /**
     * Returns the folder the item belongs to, or null.
     */
    public function getFolder(): ?VaultFolder
    {
        return $this->folder;
    }

    /**
     * Moves the item to another folder (or none) and touches the update timestamp.
     */
    public function setFolder(?VaultFolder $folder): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->folder = $folder;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns the encrypted payload.
     */
    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    /**
     * Replaces the encrypted payload and touches the update timestamp.
     */
    public function setCiphertext(string $ciphertext): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->ciphertext = $ciphertext;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns when the item was moved to the trash, or null when active.
     */
    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    /**
     * Whether the item is in the trash.
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt instanceof DateTimeImmutable;
    }

    /**
     * Moves the item to the trash (soft delete).
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
     * Restores the item from the trash.
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

    /**
     * Returns the tags attached to the item.
     *
     * @return Collection<int, VaultTag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * Replaces the item tags with the given list.
     *
     * @param list<VaultTag> $tags
     */
    public function setTags(array $tags): self
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            $this->tags->add($tag);
        }
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Returns the names of the attached tags.
     *
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
