<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\Entity\VaultItem;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * ITEM_FIELD_REVEAL_CHECK (1.16.0): vetoable check before one field (or the attachment) of an item leaves the vault,
 * after the View access check. For example, keep read-only grantees from revealing a CVV. Carries no value.
 */
final class VaultFieldRevealCheckEvent extends Event
{
    private bool $granted = true;

    /**
     * Creates the event for one field reveal.
     */
    public function __construct(
        private readonly UserInterface $user,
        private readonly VaultItem $item,
        private readonly string $field,
        private readonly string $purpose,
        private readonly string $channel,
    ) {
    }

    /**
     * The user asking for the value.
     */
    public function getUser(): UserInterface
    {
        return $this->user;
    }

    /**
     * The item (its payload is not decrypted yet).
     */
    public function getItem(): VaultItem
    {
        return $this->item;
    }

    /**
     * Payload field name (`attachment` for the attachment download).
     */
    public function getField(): string
    {
        return $this->field;
    }

    /**
     * `reveal`, `copy` or `attachment`.
     */
    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * `web` (the only channel of this check today).
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * Refuses the reveal (answered like a field that does not exist).
     */
    public function deny(): void
    {
        // @igor-ignore - Per-dispatch event object; not shared worker state.
        $this->granted = false;
    }

    /**
     * Whether the reveal may go on.
     */
    public function isGranted(): bool
    {
        return $this->granted;
    }
}
