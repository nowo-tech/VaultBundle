<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultTagRepository;
use Nowo\VaultBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmVaultTagRepository::class)]
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

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
        $this->items     = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreator(): object
    {
        return $this->creator;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, VaultItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }
}
