<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * HEALTH_REPORT_GENERATED (1.16.0): counts only (no item id, no title, no value).
 */
final class VaultHealthReportGeneratedEvent extends Event
{
    /**
     * Creates the event.
     */
    public function __construct(
        private readonly ?string $actorId,
        private readonly string $ownerKey,
        private readonly int $examined,
        private readonly int $weak,
        private readonly int $reused,
        private readonly int $old,
        private readonly int $breached,
    ) {
    }

    /**
     * The viewer's user id.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Owner key (`user:<id>` / `scope:<key>`).
     */
    public function getOwnerKey(): string
    {
        return $this->ownerKey;
    }

    /**
     * Items examined.
     */
    public function getExamined(): int
    {
        return $this->examined;
    }

    /**
     * Weak passwords.
     */
    public function getWeak(): int
    {
        return $this->weak;
    }

    /**
     * Items sharing a password with another examined item.
     */
    public function getReused(): int
    {
        return $this->reused;
    }

    /**
     * Old passwords.
     */
    public function getOld(): int
    {
        return $this->old;
    }

    /**
     * Breached passwords.
     */
    public function getBreached(): int
    {
        return $this->breached;
    }
}
