<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultSavepoint;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Psr\Clock\ClockInterface;
use SortDirection;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_string;

/**
 * Doctrine ORM implementation of the vault grant repository.
 *
 * Since 1.16.0 the access-related reads ({@see self::findByGrantee()}, {@see self::findGrantedResourceIds()},
 * {@see self::countByResources()}) only return active grants (`expires_at IS NULL OR expires_at > now`, the injected
 * clock). {@see self::findByResource()}, {@see self::findOne()} and {@see self::findById()} also return expired,
 * not yet purged grants (the share page shows them as expired; the upsert updates them); every access decision
 * re-checks {@see VaultGrant::isActiveAt()}.
 */
final readonly class DoctrineOrmVaultGrantRepository implements VaultGrantRepositoryInterface, VaultGrantExpiryRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    /**
     * Creates the repository with the entity manager, an optional registry / manager name and the clock.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?ClockInterface $clock = null,
        private ?VaultUserReferenceResolverInterface $users = null,
    ) {
    }

    /**
     * Persists and flushes the grant.
     */
    public function save(VaultGrant $grant): void
    {
        $this->em()->persist($grant);
        $this->em()->flush();
    }

    /**
     * Removes and flushes the grant.
     */
    public function remove(VaultGrant $grant): void
    {
        $this->em()->remove($grant);
        $this->em()->flush();
    }

    /**
     * Returns the grants on a resource.
     */
    public function findByResource(VaultResourceType $type, string $resourceId): array
    {
        /* @var list<VaultGrant> */
        return $this->em()->createQueryBuilder()
            ->select('g')
            ->from(VaultGrant::class, 'g')
            ->where('g.resourceType = :type')
            ->andWhere('g.resourceId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $resourceId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns the grants given to a grantee.
     */
    public function findByGrantee(GranteeType $type, string $granteeId): array
    {
        /** @var list<VaultGrant> $grants */
        $grants = $this->em()->createQueryBuilder()
            ->select('g')
            ->from(VaultGrant::class, 'g')
            ->where('g.granteeType = :type')
            ->andWhere('g.granteeId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $granteeId)
            ->andWhere('g.expiresAt IS NULL OR g.expiresAt > :now')
            ->setParameter('now', $this->now())
            ->getQuery()
            ->getResult();

        // 1.17.0 (LQ-V-P2-M1): only the grantee itself, compared byte for byte, whatever the column collation.
        return array_values(array_filter($grants, static fn (VaultGrant $grant): bool => $grant->getGranteeId() === $granteeId));
    }

    /**
     * Finds the grant of a grantee on a resource, or null.
     */
    public function findOne(
        VaultResourceType $resourceType,
        string $resourceId,
        GranteeType $granteeType,
        string $granteeId,
    ): ?VaultGrant {
        /** @var list<VaultGrant> $grants */
        $grants = $this->em()->createQueryBuilder()
            ->select('g')
            ->from(VaultGrant::class, 'g')
            ->where('g.resourceType = :rt')
            ->andWhere('g.resourceId = :rid')
            ->andWhere('g.granteeType = :gt')
            ->andWhere('g.granteeId = :gid')
            ->setParameter('rt', $resourceType)
            ->setParameter('rid', $resourceId)
            ->setParameter('gt', $granteeType)
            ->setParameter('gid', $granteeId)
            ->getQuery()
            ->getResult();

        // 1.17.0 (LQ-V-P2-M1): a case- or accent-insensitive collation may return another grantee's row; never that one.
        foreach ($grants as $grant) {
            if ($grant->getGranteeId() === $granteeId) {
                return $grant;
            }
        }

        return null;
    }

    /**
     * Finds a grant by id.
     */
    public function findById(string $id): ?VaultGrant
    {
        /* @var VaultGrant|null */
        return $this->em()->find(VaultGrant::class, $id);
    }

    /**
     * Counts grants per resource id for the given resources.
     */
    public function countByResources(VaultResourceType $type, array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [];
        }

        /** @var list<array{resourceId: string, grantCount: int|string}> $rows */
        $rows = $this->em()->createQueryBuilder()
            ->select('g.resourceId AS resourceId, COUNT(g.id) AS grantCount')
            ->from(VaultGrant::class, 'g')
            ->where('g.resourceType = :type')
            ->andWhere('g.resourceId IN (:ids)')
            ->andWhere('g.expiresAt IS NULL OR g.expiresAt > :now')
            ->setParameter('type', $type)
            ->setParameter('ids', $resourceIds)
            ->setParameter('now', $this->now())
            ->groupBy('g.resourceId')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['resourceId']] = (int) $row['grantCount'];
        }

        return $counts;
    }

    /**
     * Returns the deduplicated ids of resources granted to the user directly or through the given teams.
     */
    public function findGrantedResourceIds(object $user, VaultResourceType $resourceType, array $teamIds = []): array
    {
        $ids    = [];
        $userId = UserIdResolver::getId($user);

        if ($userId !== null) {
            foreach ($this->findByGrantee(GranteeType::User, $userId) as $grant) {
                if ($grant->getResourceType() === $resourceType) {
                    $ids[] = $grant->getResourceId();
                }
            }
        }

        foreach ($teamIds as $teamId) {
            foreach ($this->findByGrantee(GranteeType::Team, $teamId) as $grant) {
                if ($grant->getResourceType() === $resourceType) {
                    $ids[] = $grant->getResourceId();
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Active grants on the given items and folders for the user or one of the teams (one query).
     */
    public function findActiveForGrantees(array $itemIds, array $folderIds, ?string $userId, array $teamIds, DateTimeImmutable $now): array
    {
        if (($itemIds === [] && $folderIds === []) || ($userId === null && $teamIds === [])) {
            return [];
        }

        $qb        = $this->em()->createQueryBuilder()->select('g')->from(VaultGrant::class, 'g');
        $resources = $qb->expr()->orX();
        if ($itemIds !== []) {
            $resources->add('g.resourceType = :itemType AND g.resourceId IN (:itemIds)');
            $qb->setParameter('itemType', VaultResourceType::Item)->setParameter('itemIds', $itemIds);
        }
        if ($folderIds !== []) {
            $resources->add('g.resourceType = :folderType AND g.resourceId IN (:folderIds)');
            $qb->setParameter('folderType', VaultResourceType::Folder)->setParameter('folderIds', $folderIds);
        }

        $grantees = $qb->expr()->orX();
        if ($userId !== null) {
            $grantees->add('g.granteeType = :userType AND g.granteeId = :userId');
            $qb->setParameter('userType', GranteeType::User)->setParameter('userId', $userId);
        }
        if ($teamIds !== []) {
            $grantees->add('g.granteeType = :teamType AND g.granteeId IN (:teamIds)');
            $qb->setParameter('teamType', GranteeType::Team)->setParameter('teamIds', $teamIds);
        }

        /** @var list<VaultGrant> $grants */
        $grants = $qb->where($resources)
            ->andWhere($grantees)
            ->andWhere('g.expiresAt IS NULL OR g.expiresAt > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        // 1.17.0 (LQ-V-P2-M1): keep the grants of this user and these teams only, compared byte for byte, whatever the
        // column collation matched.
        $teams = array_map(strval(...), $teamIds);

        return array_values(array_filter($grants, static fn (VaultGrant $grant): bool => $grant->getGranteeType() === GranteeType::User
            ? $userId !== null && $grant->getGranteeId() === $userId
            : in_array($grant->getGranteeId(), $teams, true)));
    }

    /**
     * Grants with `expires_at <= :now`, oldest expiry first.
     */
    public function findExpired(DateTimeImmutable $now, int $limit): array
    {
        /* @var list<VaultGrant> */
        return $this->expiredQuery($now)
            ->select('g')
            ->orderBy('g.expiresAt', SortDirection::Ascending)
            ->addOrderBy('g.id', SortDirection::Ascending)
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * Counts grants with `expires_at <= :now`.
     */
    public function countExpired(DateTimeImmutable $now): int
    {
        return (int) $this->expiredQuery($now)->select('COUNT(g.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Conditional delete of one expired grant; true when this call deleted it.
     */
    public function deleteExpired(string $id, DateTimeImmutable $now): bool
    {
        $query = $this->em()->createQueryBuilder()
            ->delete(VaultGrant::class, 'g')
            ->where('g.id = :id')
            ->andWhere('g.expiresAt IS NOT NULL')
            ->andWhere('g.expiresAt <= :now')
            ->setParameter('id', $id)
            ->setParameter('now', $now)
            ->getQuery();
        $deleted = (int) VaultSavepoint::write($this->em()->getConnection(), static fn (): mixed => $query->execute());

        return $this->afterDelete($id, $deleted);
    }

    /**
     * Conditional delete of one grant; true when this call deleted it.
     */
    public function deleteById(string $id): bool
    {
        $query = $this->em()->createQueryBuilder()
            ->delete(VaultGrant::class, 'g')
            ->where('g.id = :id')
            ->setParameter('id', $id)
            ->getQuery();
        $deleted = (int) VaultSavepoint::write($this->em()->getConnection(), static fn (): mixed => $query->execute());

        return $this->afterDelete($id, $deleted);
    }

    /**
     * Conditional update of permission and expiry; false when the row is gone.
     */
    public function updatePermissionAndExpiry(string $id, VaultPermission $permission, ?DateTimeImmutable $expiresAt): bool
    {
        $query = $this->em()->createQueryBuilder()
            ->update(VaultGrant::class, 'g')
            ->set('g.permission', ':permission')
            ->set('g.expiresAt', ':expiresAt')
            ->where('g.id = :id')
            ->setParameter('permission', $permission)
            ->setParameter('expiresAt', $expiresAt, 'datetime_immutable')
            ->setParameter('id', $id)
            ->getQuery();
        $updated = (int) VaultSavepoint::write($this->em()->getConnection(), static fn (): mixed => $query->execute());

        $managed = $this->em()->getUnitOfWork()->tryGetById($id, VaultGrant::class);
        if ($managed instanceof VaultGrant) {
            if ($updated === 1) {
                $this->em()->refresh($managed);
            } else {
                $this->em()->detach($managed);
            }
        }

        return $updated === 1;
    }

    /**
     * Persists and flushes a new grant.
     */
    public function insert(VaultGrant $grant): void
    {
        $this->em()->persist($grant);
        $this->em()->flush();
    }

    /**
     * Owner keys of items and folders (two scalar queries, no entity hydrated).
     */
    public function resolveOwnerKeys(array $resources): array
    {
        $ids = [VaultResourceType::Item->value => [], VaultResourceType::Folder->value => []];
        foreach ($resources as [$type, $id]) {
            $ids[$type->value][] = $id;
        }

        $keys = [];
        // 1.17.0: with the id mapping the creator is a plain column, not a relation.
        $creatorId = $this->users?->mapsById() === true ? 'r.creator' : 'IDENTITY(r.creator)';
        foreach ([VaultResourceType::Item->value => VaultItem::class, VaultResourceType::Folder->value => VaultFolder::class] as $type => $class) {
            if ($ids[$type] === []) {
                continue;
            }

            /** @var list<array{id: string, ownerScope: string|null, creatorId: int|string|null}> $rows */
            $rows = $this->em()->createQueryBuilder()
                ->select('r.id AS id', 'r.ownerScope AS ownerScope', $creatorId . ' AS creatorId')
                ->from($class, 'r')
                ->where('r.id IN (:ids)')
                ->setParameter('ids', $ids[$type])
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $keys[$type . ':' . $row['id']] = is_string($row['ownerScope']) ? 'scope:' . $row['ownerScope'] : 'user:' . $row['creatorId'];
            }
        }

        return $keys;
    }

    /**
     * Deletes the grants of the resource and the resource in one transaction.
     */
    public function purgeResourceWithGrants(VaultItem|VaultFolder $resource): array
    {
        $type = $resource instanceof VaultItem ? VaultResourceType::Item : VaultResourceType::Folder;

        /** @var list<VaultGrant> $deleted */
        $deleted = $this->em()->wrapInTransaction(function (EntityManagerInterface $em) use ($resource, $type): array {
            $grants = $this->findByResource($type, $resource->getId());
            foreach ($grants as $grant) {
                $em->remove($grant);
            }
            $em->remove($resource);
            $em->flush();

            return $grants;
        });

        return $deleted;
    }

    /**
     * Query builder over the expired grants (`expires_at <= :now`).
     */
    private function expiredQuery(DateTimeImmutable $now): QueryBuilder
    {
        return $this->em()->createQueryBuilder()
            ->from(VaultGrant::class, 'g')
            ->where('g.expiresAt IS NOT NULL')
            ->andWhere('g.expiresAt <= :now')
            ->setParameter('now', $now);
    }

    /**
     * Detaches a deleted grant still in the identity map; returns whether one row was deleted.
     */
    private function afterDelete(string $id, int $deleted): bool
    {
        $managed = $this->em()->getUnitOfWork()->tryGetById($id, VaultGrant::class);
        if ($managed instanceof VaultGrant) {
            $this->em()->detach($managed);
        }

        return $deleted === 1;
    }

    /**
     * Current instant of the injected clock (system clock when none is wired).
     */
    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
