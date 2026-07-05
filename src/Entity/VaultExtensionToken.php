<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultExtensionTokenRepository;
use Nowo\VaultBundle\ValueObject\Uuid;

#[ORM\Entity(repositoryClass: DoctrineOrmVaultExtensionTokenRepository::class)]
#[ORM\Table(name: 'vault_extension_tokens')]
#[ORM\Index(name: 'vault_extension_tokens_hash_idx', columns: ['token_hash'])]
#[ORM\Index(name: 'vault_extension_tokens_expires_idx', columns: ['expires_at'])]
class VaultExtensionToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private DateTimeImmutable $expiresAt,
        #[ORM\ManyToOne(targetEntity: 'App\Entity\User')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $user,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getUser(): object
    {
        return $this->user;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function touch(): self
    {
        $this->lastUsedAt = new DateTimeImmutable();

        return $this;
    }
}
