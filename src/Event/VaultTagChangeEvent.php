<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * TAG_CHANGED (1.19.0, REQ-ARCH-001 rule 4): a tag was created or deleted. Ids only (no name); dispatched after the
 * vault's own flush. Feeds a host activity log.
 */
final class VaultTagChangeEvent extends Event
{
    public const KIND_CREATED = 'created';
    public const KIND_DELETED = 'deleted';

    /**
     * Creates the event.
     */
    public function __construct(
        private readonly ?string $actorId,
        private readonly string $tagId,
        private readonly string $ownerKey,
        private readonly string $kind,
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Actor user id, or null when unknown (system or not given).
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Tag id.
     */
    public function getTagId(): string
    {
        return $this->tagId;
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
     * When the change happened.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
