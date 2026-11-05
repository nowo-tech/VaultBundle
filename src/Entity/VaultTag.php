<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\ValueObject\Uuid;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity for a tag owned by its creator and attached to vault items.
 *
 * Since 1.16.0 a tag is owned by a user (`creator_id`) or shared by a host scope (`owner_scope`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_tags')]
#[ORM\UniqueConstraint(name: 'vault_tags_creator_name_unique', columns: ['creator_id', 'name'])]
#[ORM\UniqueConstraint(name: 'vault_tags_scope_name_unique', columns: ['owner_scope', 'name'])]
class VaultTag
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, VaultItem> */
    #[ORM\ManyToMany(targetEntity: VaultItem::class, mappedBy: 'tags')]
    private Collection $items;

    /** Host scope key of a scope tag, null for a personal tag (1.16.0). */
    #[ORM\Column(name: 'owner_scope', type: 'string', length: 128, nullable: true)]
    private ?string $ownerScope;

    /**
     * Creates a tag with the given name for a creator. A scope tag passes `$creator = null` and `$ownerScope` (use
     * {@see self::forScope()}).
     *
     * @throws VaultOwnerException when the owner is ambiguous or the scope key is invalid
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        /** Personal owner; null for a scope tag (1.16.0). */
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?object $creator,
        ?string $ownerScope = null,
    ) {
        $this->ownerScope = VaultOwner::fromColumns($creator, $ownerScope)->getScope();
        $this->id         = Uuid::generate()->toString();
        $this->createdAt  = new DateTimeImmutable();
        $this->items      = new ArrayCollection();
    }

    /**
     * Creates a scope tag (1.16.0), shared by every member of the scope.
     *
     * @throws VaultOwnerException when the scope key is invalid
     */
    public static function forScope(string $name, string $scope): self
    {
        return new self($name, null, $scope);
    }

    /**
     * Returns the tag UUID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the tag name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the user that owns the personal tag.
     *
     * @deprecated since 1.16.0, use getOwner()
     *
     * @throws VaultOwnerException for a scope tag
     */
    public function getCreator(): object
    {
        if ($this->creator === null) {
            throw new VaultOwnerException('A scope tag has no personal owner; use getOwner().');
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
     * The owning scope key, or null for a personal tag (1.16.0).
     */
    public function getOwnerScope(): ?string
    {
        return $this->ownerScope;
    }

    /**
     * Returns the creation timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the items carrying this tag.
     *
     * @return Collection<int, VaultItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }
}
