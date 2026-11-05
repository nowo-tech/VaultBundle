<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function max;
use function min;

/**
 * Housekeeping of temporary grants (1.16.0): deletes the expired rows and emits GRANT_EXPIRED once per row.
 *
 * Enforcement never depends on this purge: every read path already ignores a grant at `expiresAt`. Each row is
 * deleted with a conditional `DELETE … WHERE id AND expires_at <= :now`, so two concurrent purgers emit each event
 * once, and a grant extended between the select and the delete survives. Events are dispatched after each batch.
 */
final readonly class VaultGrantExpiryService
{
    /**
     * Stores the grant repository (it must implement {@see VaultGrantExpiryRepositoryInterface}), the grant service
     * (event dispatch), the clock and the optional logger.
     */
    public function __construct(
        private VaultGrantRepositoryInterface $grantRepository,
        private VaultGrantService $grantService,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Deletes up to `$limit` (all when null) expired grants in batches of `$batchSize`.
     *
     * @param callable(list<string>): void|null $onBatchSelected called with the ids of each selected batch, before they are deleted (progress reporting)
     */
    public function purgeExpired(int $batchSize = 500, ?int $limit = null, bool $dryRun = false, ?callable $onBatchSelected = null): VaultGrantPurgeResult
    {
        $repository = $this->grantRepository;
        if (!$repository instanceof VaultGrantExpiryRepositoryInterface) {
            throw new LogicException('The grant repository does not implement VaultGrantExpiryRepositoryInterface.');
        }

        $batchSize = max(1, $batchSize);
        $now       = $this->clock?->now() ?? new DateTimeImmutable();

        if ($dryRun) {
            $count = $repository->countExpired($now);
            $this->log($limit === null ? $count : min($count, $limit), true);

            return new VaultGrantPurgeResult($limit === null ? $count : min($count, $limit), true);
        }

        $expired = 0;
        $skipped = [];
        while ($limit === null || $expired < $limit) {
            $take  = $limit === null ? $batchSize : min($batchSize, $limit - $expired);
            $batch = array_values(array_filter(
                $repository->findExpired($now, $take + count($skipped)),
                static fn (VaultGrant $grant): bool => !isset($skipped[$grant->getId()]),
            ));
            if ($batch === []) {
                break;
            }

            $batch = array_slice($batch, 0, $take);
            if ($onBatchSelected !== null) {
                $onBatchSelected(array_map(static fn (VaultGrant $grant): string => $grant->getId(), $batch));
            }

            $deleted = [];
            foreach ($batch as $grant) {
                if ($repository->deleteExpired($grant->getId(), $now)) {
                    $deleted[] = $grant;
                } else {
                    // Gone or extended meanwhile: never selected again by this run.
                    $skipped[$grant->getId()] = true;
                }
            }

            $owners = $repository->resolveOwnerKeys(array_map(static fn (VaultGrant $grant): array => [$grant->getResourceType(), $grant->getResourceId()], $deleted));
            foreach ($deleted as $grant) {
                $this->grantService->dispatchChange(
                    VaultEvents::GRANT_EXPIRED,
                    $grant,
                    null,
                    VaultGrantChangeEvent::REASON_EXPIRED,
                    ownerKey: $owners[$grant->getResourceType()->value . ':' . $grant->getResourceId()] ?? null,
                );
            }

            $expired += count($deleted);
        }

        $this->log($expired, false);

        return new VaultGrantPurgeResult($expired, false);
    }

    private function log(int $count, bool $dryRun): void
    {
        ($this->logger ?? new NullLogger())->info('Expired vault grants purged.', [
            'bundle'  => 'nowo_vault',
            'action'  => 'grants_purge_expired',
            'count'   => $count,
            'dry_run' => $dryRun,
        ]);
    }
}
