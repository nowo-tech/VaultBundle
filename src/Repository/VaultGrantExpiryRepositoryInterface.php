<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\ValueObject\VaultOwner;

/**
 * Grant queries added in 1.16.0 (expiry, race-safe writes, batched access checks). Implemented by the Doctrine grant
 * repository; {@see VaultGrantRepositoryInterface} is unchanged, and services fall back to it (with the expiry
 * re-checked in PHP) when a custom repository does not implement this interface.
 */
interface VaultGrantExpiryRepositoryInterface
{
    /**
     * Active grants (`expires_at IS NULL OR expires_at > :now`) on the given items and folders whose grantee is the
     * user or one of the teams, in one query.
     *
     * @param list<string> $itemIds
     * @param list<string> $folderIds
     * @param list<string> $teamIds
     *
     * @return list<VaultGrant>
     */
    public function findActiveForGrantees(array $itemIds, array $folderIds, ?string $userId, array $teamIds, DateTimeImmutable $now): array;

    /**
     * Grants with `expires_at <= :now`, oldest expiry first.
     *
     * @return list<VaultGrant>
     */
    public function findExpired(DateTimeImmutable $now, int $limit): array;

    /**
     * Counts grants with `expires_at <= :now`.
     */
    public function countExpired(DateTimeImmutable $now): int;

    /**
     * `DELETE … WHERE id = :id AND expires_at IS NOT NULL AND expires_at <= :now`: true only when this call deleted
     * the row (a concurrent purge, revoke or extension makes it false).
     */
    public function deleteExpired(string $id, DateTimeImmutable $now): bool;

    /**
     * `DELETE … WHERE id = :id`: true only when this call deleted the row.
     */
    public function deleteById(string $id): bool;

    /**
     * `UPDATE … SET permission, expires_at WHERE id = :id`: false when the row is gone (purged meanwhile).
     */
    public function updatePermissionAndExpiry(string $id, VaultPermission $permission, ?DateTimeImmutable $expiresAt): bool;

    /**
     * Inserts a new grant (persist + flush). A concurrent insert for the same resource and grantee raises the
     * unique-constraint violation of the DBAL driver.
     */
    public function insert(VaultGrant $grant): void;

    /**
     * Owner keys ({@see VaultOwner::key()}) of resources, in two scalar queries.
     *
     * @param list<array{0: VaultResourceType, 1: string}> $resources
     *
     * @return array<string, string> "<type>:<id>" => owner key (missing resources are absent)
     */
    public function resolveOwnerKeys(array $resources): array;

    /**
     * Deletes the grants of an item or folder and the resource itself in one transaction.
     *
     * @return list<VaultGrant> the deleted grants (detached)
     */
    public function purgeResourceWithGrants(VaultItem|VaultFolder $resource): array;
}
