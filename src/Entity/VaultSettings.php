<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Nowo\DoctrineEncryptBundle\Configuration\Encrypted;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultSettingsRepository;

#[ORM\Entity(repositoryClass: DoctrineOrmVaultSettingsRepository::class)]
#[ORM\Table(name: 'vault_settings')]
class VaultSettings
{
    public const DEFAULT_SCOPE = 'default';

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

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

    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setValues(array $values): self
    {
        $this->values    = $values;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function mergeValues(array $values): self
    {
        return $this->setValues(array_replace_recursive($this->values, $values));
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getEncryptionKey(): ?string
    {
        return $this->encryptionKey;
    }

    public function setEncryptionKey(?string $encryptionKey): self
    {
        $this->encryptionKey = $encryptionKey;
        $this->updatedAt     = new DateTimeImmutable();

        return $this;
    }
}
