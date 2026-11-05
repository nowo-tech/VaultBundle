<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * ITEM_CHANGED (1.16.0, Q-B8): an item was created, updated, trashed, restored, purged or moved. Ids only (no title,
 * no payload); dispatched after the flush. Feeds a host activity log.
 */
final class VaultItemChangeEvent extends Event
{
    public const KIND_CREATED  = 'created';
    public const KIND_UPDATED  = 'updated';
    public const KIND_TRASHED  = 'trashed';
    public const KIND_RESTORED = 'restored';
    public const KIND_PURGED   = 'purged';
    public const KIND_MOVED    = 'moved';

    /**
     * Creates the event.
     */
    public function __construct(
        private readonly ?string $actorId,
        private readonly string $itemId,
        private readonly string $ownerKey,
        private readonly string $kind,
        private readonly bool $secretChanged,
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Actor user id, or null when unknown (system).
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Item id.
     */
    public function getItemId(): string
    {
        return $this->itemId;
    }

    /**
     * Owner key (`user:<id>` / `scope:<key>`).
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * One of the KIND_* constants.
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Whether a secret field changed (created items: true).
     */
    public function isSecretChanged(): bool
    {
        return $this->secretChanged;
    }

    /**
     * When the change happened.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
