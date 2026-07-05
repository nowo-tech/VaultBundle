<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Entity\VaultTag;

final readonly class DoctrineOrmVaultTagRepository implements VaultTagRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(VaultTag $tag): void
    {
        $this->entityManager->persist($tag);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?VaultTag
    {
        return $this->entityManager->find(VaultTag::class, $id);
    }

    public function findOneByCreatorAndName(object $creator, string $name): ?VaultTag
    {
        /* @var VaultTag|null */
        return $this->entityManager->createQueryBuilder()
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
        return $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(VaultTag::class, 't')
            ->where('t.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function remove(VaultTag $tag): void
    {
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }
}
