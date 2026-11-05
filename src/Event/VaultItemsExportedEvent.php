<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * ITEMS_EXPORTED (1.16.0, contract §9.5): a decrypted personal export passed every check and is about to stream.
 * Dispatched before the first byte, so an aborted download is still audited; a listener that throws refuses the export
 * (503). Counts and factor names only: never an item, a value or the proof.
 */
final class VaultItemsExportedEvent extends Event
{
    /**
     * @param list<string> $factors re-authentication factors of the proof (e.g. `password`, `totp`)
     */
    public function __construct(
        private readonly ?string $actorId,
        private readonly int $count,
        private readonly string $format,
        private readonly bool $includesAttachments,
        private readonly array $factors,
        private readonly DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Id of the exporting user.
     */
    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    /**
     * Number of exported items.
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * `json` or `csv`.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Whether attachments are in the file (JSON with `export.include_attachments`).
     */
    public function includesAttachments(): bool
    {
        return $this->includesAttachments;
    }

    /**
     * Factors of the re-authentication.
     *
     * @return list<string>
     */
    public function getFactors(): array
    {
        return $this->factors;
    }

    /**
     * When the export was accepted (bundle clock).
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
