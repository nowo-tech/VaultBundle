<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultTag;
use SortDirection;

final readonly class DoctrineOrmVaultTagRepository implements VaultTagRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    public function save(VaultTag $tag): void
    {
        $this->em()->persist($tag);
        $this->em()->flush();
    }

    public function findById(string $id): ?VaultTag
    {
        return $this->em()->find(VaultTag::class, $id);
    }

    public function findOneByCreatorAndName(object $creator, string $name): ?VaultTag
    {
        /* @var VaultTag|null */
        return $this->em()->createQueryBuilder()
            ->select('t')
            ->from(VaultTag::class, 't')
            ->where('t.creator = :creator')
            ->andWhere('t.name = :name')
            ->setParameter('creator', $creator)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCreator(object $creator): array
    {
        /* @var list<VaultTag> */
        return $this->em()->createQueryBuilder()
            ->select('t')
            ->from(VaultTag::class, 't')
            ->where('t.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('t.name', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function remove(VaultTag $tag): void
    {
        $this->em()->remove($tag);
        $this->em()->flush();
    }
}
