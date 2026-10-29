<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Support\UserIdResolver;

/**
 * Doctrine ORM implementation of the vault grant repository.
 */
final readonly class DoctrineOrmVaultGrantRepository implements VaultGrantRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    /**
     * Creates the repository with the entity manager and an optional registry / manager name.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
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
        /* @var list<VaultGrant> */
        return $this->em()->createQueryBuilder()
            ->select('g')
            ->from(VaultGrant::class, 'g')
            ->where('g.granteeType = :type')
            ->andWhere('g.granteeId = :id')
            ->setParameter('type', $type)
            ->setParameter('id', $granteeId)
            ->getQuery()
            ->getResult();
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
        /* @var VaultGrant|null */
        return $this->em()->createQueryBuilder()
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
            ->getOneOrNullResult();
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
            ->setParameter('type', $type)
            ->setParameter('ids', $resourceIds)
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
}
