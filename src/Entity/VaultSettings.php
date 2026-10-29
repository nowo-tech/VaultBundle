<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;

/**
 * Doctrine entity storing database-backed runtime configuration values per scope.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault_settings')]
class VaultSettings
{
    public const DEFAULT_SCOPE = 'default';

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    /**
     * Creates the settings row for a scope with initial values.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $scope = self::DEFAULT_SCOPE,
        /** @var array<string, mixed> Runtime-overridable nowo_vault keys (see VaultRuntimeConfigSchema). */
        #[ORM\Column(name: 'config_values', type: 'json')]
        private array $values = [],
        /** Base64 libsodium master key override; encrypted at rest via DoctrineEncryptBundle. */
        #[ORM\Column(name: 'encryption_key', type: 'text', nullable: true)]
        #[Encrypted]
        private ?string $encryptionKey = null,
    ) {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Returns the settings scope.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Returns the stored configuration values.
     *
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Replaces the stored values and touches the update timestamp.
     *
     * @param array<string, mixed> $values
     */
    public function setValues(array $values): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->values = $values;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * Recursively merges values over the stored ones.
     *
     * @param array<string, mixed> $values
     */
    public function mergeValues(array $values): self
    {
        return $this->setValues(array_replace_recursive($this->values, $values));
    }

    /**
     * Returns the last update timestamp.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the stored encryption key, or null when none is stored.
     */
    public function getEncryptionKey(): ?string
    {
        return $this->encryptionKey;
    }

    /**
     * Sets the stored encryption key and touches the update timestamp.
     */
    public function setEncryptionKey(?string $encryptionKey): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->encryptionKey = $encryptionKey;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }
}
