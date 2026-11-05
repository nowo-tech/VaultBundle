<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

/**
 * Counts of one expired-grant purge run (1.16.0).
 */
final readonly class VaultGrantPurgeResult
{
    /**
     * @param int $expired grants deleted by this run (or, with dry-run, that would be deleted)
     */
    public function __construct(
        public int $expired,
        public bool $dryRun,
    ) {
    }

    /**
     * JSON-serialisable summary printed by the command.
     *
     * @return array{expired: int, dry_run: bool}
     */
    public function toArray(): array
    {
        return ['expired' => $this->expired, 'dry_run' => $this->dryRun];
    }
}
