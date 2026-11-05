<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Stub;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Exception;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Support\UserIdResolver;

use function array_slice;
use function array_values;
use function count;
use function in_array;

use const PHP_INT_MAX;

/**
 * In-memory grant repository implementing the 1.16.0 expiry interface (unit tests of the grant rules and races).
 */
class InMemoryGrantRepository implements VaultGrantRepositoryInterface, VaultGrantExpiryRepositoryInterface
{
    /** @var array<string, VaultGrant> */
    public array $grants = [];

    /** @var array<string, string> "<type>:<id>" => owner key */
    public array $owners = [];

    /** Simulates a concurrent insert of the same grantee: the next insert() stores this grant and throws. */
    public ?VaultGrant $raceInsert = null;

    /** Simulates a purge between select and update: the next update finds no row. */
    public bool $raceUpdate = false;

    public function __construct(private readonly DateTimeImmutable $now = new DateTimeImmutable('2026-11-05 12:00:00'))
    {
    }

    public function save(VaultGrant $grant): void
    {
        $this->grants[$grant->getId()] = $grant;
    }

    public function remove(VaultGrant $grant): void
    {
        unset($this->grants[$grant->getId()]);
    }

    public function findByResource(VaultResourceType $type, string $resourceId): array
    {
        return array_values(array_filter($this->grants, static fn (VaultGrant $g): bool => $g->getResourceType() === $type && $g->getResourceId() === $resourceId));
    }

    public function findByGrantee(GranteeType $type, string $granteeId): array
    {
        return array_values(array_filter($this->grants, fn (VaultGrant $g): bool => $g->getGranteeType() === $type && $g->getGranteeId() === $granteeId && $g->isActiveAt($this->now)));
    }

    public function findOne(VaultResourceType $resourceType, string $resourceId, GranteeType $granteeType, string $granteeId): ?VaultGrant
    {
        foreach ($this->grants as $grant) {
            if ($grant->getResourceType() === $resourceType && $grant->getResourceId() === $resourceId && $grant->getGranteeType() === $granteeType && $grant->getGranteeId() === $granteeId) {
                return $grant;
            }
        }

        return null;
    }

    public function findById(string $id): ?VaultGrant
    {
        return $this->grants[$id] ?? null;
    }

    public function countByResources(VaultResourceType $type, array $resourceIds): array
    {
        $counts = [];
        foreach ($this->grants as $grant) {
            if ($grant->getResourceType() === $type && in_array($grant->getResourceId(), $resourceIds, true) && $grant->isActiveAt($this->now)) {
                $counts[$grant->getResourceId()] = ($counts[$grant->getResourceId()] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function findGrantedResourceIds(object $user, VaultResourceType $resourceType, array $teamIds = []): array
    {
        $ids = [];
        foreach ($this->grants as $grant) {
            $mine = ($grant->getGranteeType() === GranteeType::User && $grant->getGranteeId() === UserIdResolver::getId($user))
                || ($grant->getGranteeType() === GranteeType::Team && in_array($grant->getGranteeId(), $teamIds, true));
            if ($mine && $grant->getResourceType() === $resourceType && $grant->isActiveAt($this->now)) {
                $ids[$grant->getResourceId()] = $grant->getResourceId();
            }
        }

        return array_values($ids);
    }

    public function findActiveForGrantees(array $itemIds, array $folderIds, ?string $userId, array $teamIds, DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->grants, static function (VaultGrant $g) use ($itemIds, $folderIds, $userId, $teamIds, $now): bool {
            $resource = ($g->getResourceType() === VaultResourceType::Item && in_array($g->getResourceId(), $itemIds, true))
                || ($g->getResourceType() === VaultResourceType::Folder && in_array($g->getResourceId(), $folderIds, true));
            $grantee = ($g->getGranteeType() === GranteeType::User && $g->getGranteeId() === $userId)
                || ($g->getGranteeType() === GranteeType::Team && in_array($g->getGranteeId(), $teamIds, true));

            return $resource && $grantee && $g->isActiveAt($now);
        }));
    }

    public function findExpired(DateTimeImmutable $now, int $limit): array
    {
        return array_slice(array_values(array_filter($this->grants, static fn (VaultGrant $g): bool => $g->isTemporary() && !$g->isActiveAt($now))), 0, $limit);
    }

    public function countExpired(DateTimeImmutable $now): int
    {
        return count($this->findExpired($now, PHP_INT_MAX));
    }

    public function deleteExpired(string $id, DateTimeImmutable $now): bool
    {
        $grant = $this->grants[$id] ?? null;
        if ($grant === null || !$grant->isTemporary() || $grant->isActiveAt($now)) {
            return false;
        }
        unset($this->grants[$id]);

        return true;
    }

    public function deleteById(string $id): bool
    {
        if (!isset($this->grants[$id])) {
            return false;
        }
        unset($this->grants[$id]);

        return true;
    }

    public function updatePermissionAndExpiry(string $id, VaultPermission $permission, ?DateTimeImmutable $expiresAt): bool
    {
        if ($this->raceUpdate) {
            $this->raceUpdate = false;
            unset($this->grants[$id]);

            return false;
        }

        if (!isset($this->grants[$id])) {
            return false;
        }

        $this->grants[$id]->setPermission($permission)->setExpiresAt($expiresAt);

        return true;
    }

    public function insert(VaultGrant $grant): void
    {
        if ($this->raceInsert instanceof VaultGrant) {
            $winner                         = $this->raceInsert;
            $this->raceInsert               = null;
            $this->grants[$winner->getId()] = $winner;

            throw new UniqueConstraintViolationException(new class('duplicate') extends Exception implements DriverException {
                public function getSQLState(): string
                {
                    return '23000';
                }
            }, null);
        }

        $this->grants[$grant->getId()] = $grant;
    }

    public function resolveOwnerKeys(array $resources): array
    {
        $keys = [];
        foreach ($resources as [$type, $id]) {
            if (isset($this->owners[$type->value . ':' . $id])) {
                $keys[$type->value . ':' . $id] = $this->owners[$type->value . ':' . $id];
            }
        }

        return $keys;
    }

    public function purgeResourceWithGrants(VaultItem|VaultFolder $resource): array
    {
        $type    = $resource instanceof VaultItem ? VaultResourceType::Item : VaultResourceType::Folder;
        $deleted = $this->findByResource($type, $resource->getId());
        foreach ($deleted as $grant) {
            unset($this->grants[$grant->getId()]);
        }

        return $deleted;
    }
}
