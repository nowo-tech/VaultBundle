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

    /**
     * Creates the event listing the grantees allowed for sharing a resource.
     */
    public function __construct(
        private readonly UserInterface $grantedBy,
        private readonly VaultResourceType $resourceType,
        private readonly string $resourceId,
        private readonly VaultItem|VaultFolder|null $resource = null,
    ) {
    }

    /**
     * Returns the user that is sharing the resource.
     */
    public function getGrantedBy(): UserInterface
    {
        return $this->grantedBy;
    }

    /**
     * Returns the shared resource type.
     */
    public function getResourceType(): VaultResourceType
    {
        return $this->resourceType;
    }

    /**
     * Returns the shared resource id.
     */
    public function getResourceId(): string
    {
        return $this->resourceId;
    }

    /**
     * Returns the shared item, or null when the resource is a folder.
     */
    public function getItem(): ?VaultItem
    {
        return $this->resource instanceof VaultItem ? $this->resource : null;
    }

    /**
     * Returns the shared folder, or null when the resource is an item.
     */
    public function getFolder(): ?VaultFolder
    {
        return $this->resource instanceof VaultFolder ? $this->resource : null;
    }

    /**
     * Adds an allowed grantee choice.
     */
    public function addGrantee(VaultGranteeChoice $grantee): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->grantees[$grantee->getKey()] = $grantee;
    }

    /**
     * Removes a grantee choice.
     */
    public function removeGrantee(GranteeType $type, string $id): void
    {
        unset($this->grantees[VaultGranteeChoice::buildKey($type, $id)]);
    }

    /**
     * Returns the grantee choices, optionally filtered by type.
     *
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

    /**
     * Whether any grantee choice was provided.
     */
    public function hasGrantees(): bool
    {
        return $this->grantees !== [];
    }

    /**
     * Whether the grantee may be selected (any grantee is allowed when no choices were provided).
     */
    public function isGranteeAllowed(GranteeType $type, string $id): bool
    {
        if (!$this->hasGrantees()) {
            return true;
        }

        return isset($this->grantees[VaultGranteeChoice::buildKey($type, $id)]);
    }

    /**
     * Returns the display label of a grantee, or null when unknown.
     */
    public function getLabelFor(GranteeType $type, string $id): ?string
    {
        return $this->grantees[VaultGranteeChoice::buildKey($type, $id)]->label ?? null;
    }

    /**
     * Returns the grantee labels keyed by type:id.
     *
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
