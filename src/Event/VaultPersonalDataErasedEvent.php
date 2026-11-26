<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultErasureResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * PERSONAL_DATA_ERASED (1.16.0, contract §8.2): fired after the last commit of an erasure. The user id and row counts
 * per table only.
 *
 * Since 2.5.0 an erasure that joined a transaction already open on the vault's connection (UserKit's) commits with
 * it: on doctrine/dbal 4 this event (and the erasure's GRANT_REVOKED) is held until that transaction's outermost commit,
 * dispatched at the next idle point after it, and dropped when it rolls back, whatever `events.after_host_commit`
 * says. On doctrine/dbal 3 the connection reports no commit, so inside such a transaction it leaves before the commit.
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
