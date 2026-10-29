<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\ValueObject\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity for a tag owned by its creator and attached to vault items.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_tags')]
#[ORM\UniqueConstraint(name: 'vault_tags_creator_name_unique', columns: ['creator_id', 'name'])]
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

    /**
     * Creates a tag with the given name for a creator.
     */
    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->items     = new ArrayCollection();
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
     * Returns the user that created (owns) the tag.
     */
    public function getCreator(): object
    {
        return $this->creator;
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
