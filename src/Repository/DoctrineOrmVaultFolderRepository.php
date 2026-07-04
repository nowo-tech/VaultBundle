<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Entity\VaultFolder;

final readonly class DoctrineOrmVaultFolderRepository implements VaultFolderRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(VaultFolder $folder): void
    {
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
    }

    public function remove(VaultFolder $folder): void
    {
        $this->entityManager->remove($folder);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?VaultFolder
    {
        return $this->entityManager->find(VaultFolder::class, $id);
    }

    public function findByCreator(object $creator, bool $includeDeleted = false): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(VaultFolder::class, 'f')
            ->where('f.creator = :creator')
            ->setParameter('creator', $creator)
            ->orderBy('f.name', 'ASC');

        if (!$includeDeleted) {
            $qb->andWhere('f.deletedAt IS NULL');
        }

        /* @var list<VaultFolder> */
        return $qb->getQuery()->getResult();
    }

    public function findDeletedByCreator(object $creator): array
    {
        /* @var list<VaultFolder> */
        return $this->entityManager->createQueryBuilder()
            ->select('f')
            ->from(VaultFolder::class, 'f')
            ->where('f.creator = :creator')
            ->andWhere('f.deletedAt IS NOT NULL')
            ->setParameter('creator', $creator)
            ->orderBy('f.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
