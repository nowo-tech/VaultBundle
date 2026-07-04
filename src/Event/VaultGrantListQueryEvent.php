<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Fired when building the share UI for an item or folder.
 *
 * Listeners add {@see VaultGranteeChoice} entries to limit who can be granted access.
 * When at least one choice is registered, the share form shows a picker and only
 * listed users/teams (groups) are accepted on submit.
 */
final class VaultGrantListQueryEvent extends Event
{
    /** @var array<string, VaultGranteeChoice> */
    private array $grantees = [];

    public function __construct(
        private readonly UserInterface $grantedBy,
        private readonly VaultResourceType $resourceType,
        private readonly string $resourceId,
        private readonly VaultItem|VaultFolder|null $resource = null,
    ) {
    }

    public function getGrantedBy(): UserInterface
    {
        return $this->grantedBy;
    }

    public function getResourceType(): VaultResourceType
    {
        return $this->resourceType;
    }

    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    public function getItem(): ?VaultItem
    {
        return $this->resource instanceof VaultItem ? $this->resource : null;
    }

    public function getFolder(): ?VaultFolder
    {
        return $this->resource instanceof VaultFolder ? $this->resource : null;
    }

    public function addGrantee(VaultGranteeChoice $grantee): void
    {
        $this->grantees[$grantee->getKey()] = $grantee;
    }

    public function removeGrantee(GranteeType $type, string $id): void
    {
        unset($this->grantees[VaultGranteeChoice::buildKey($type, $id)]);
    }

    /**
     * @return list<VaultGranteeChoice>
     */
    public function getGrantees(?GranteeType $type = null): array
    {
        if (!$type instanceof GranteeType) {
            return array_values($this->grantees);
        }

        return array_values(array_filter(
            $this->grantees,
            static fn (VaultGranteeChoice $grantee): bool => $grantee->type === $type,
        ));
    }

    public function hasGrantees(): bool
    {
        return $this->grantees !== [];
    }

    public function isGranteeAllowed(GranteeType $type, string $id): bool
    {
        if (!$this->hasGrantees()) {
            return true;
        }

        return isset($this->grantees[VaultGranteeChoice::buildKey($type, $id)]);
    }

    public function getLabelFor(GranteeType $type, string $id): ?string
    {
        return $this->grantees[VaultGranteeChoice::buildKey($type, $id)]->label ?? null;
    }

    /**
     * @return array<string, string> Map of grantee key => label
     */
    public function getLabelMap(): array
    {
        $labels = [];
        foreach ($this->grantees as $key => $grantee) {
            $labels[$key] = $grantee->label;
        }

        return $labels;
    }
}
