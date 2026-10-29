<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VaultBundle\ValueObject\Uuid;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine entity of a browser extension Bearer token (only the SHA-256 hash of the token is stored).
 */
#[ORM\Entity]
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

    /**
     * Creates a token for $user with the given hash and expiry.
     */
    public function __construct(
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private DateTimeImmutable $expiresAt,
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $user,
    ) {
        $this->id        = Uuid::generate()->toString();
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Returns the token UUID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the SHA-256 hash of the plain Bearer token.
     */
    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * Returns the user the token belongs to.
     */
    public function getUser(): object
    {
        return $this->user;
    }

    /**
     * Returns the creation time.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the expiry time.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the last use time, or null when never used.
     */
    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * Whether the token has expired at $now (defaults to the system time; services pass their injected clock).
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new DateTimeImmutable());
    }

    /**
     * Records the last use of the token at $now (defaults to the system time; services pass their injected clock).
     */
    public function touch(?DateTimeImmutable $now = null): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->lastUsedAt = $now ?? new DateTimeImmutable();

        return $this;
    }
}
