<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultErasureResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * PERSONAL_DATA_ERASED (1.16.0, contract §8.2): fired after the last commit of an erasure. The user id and row counts
 * per table only.
 */
final class VaultPersonalDataErasedEvent extends Event
{
    /**
     * @param array<string, int> $counts rows per eraser step ({@see VaultErasureResult::toArray()})
     */
    public function __construct(
        private readonly string $userId,
        private readonly array $counts,
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Id of the erased user.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Rows deleted or anonymised per step.
     *
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /**
     * When the erasure finished (bundle clock).
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
