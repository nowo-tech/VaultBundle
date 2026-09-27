<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultFolder;

final readonly class DoctrineOrmVaultFolderRepository implements VaultFolderRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    public function save(VaultFolder $folder): void
    {
        $this->em()->persist($folder);
        $this->em()->flush();
    }

    public function remove(VaultFolder $folder): void
    {
        $this->em()->remove($folder);
        $this->em()->flush();
    }

    public function findById(string $id): ?VaultFolder
    {
        return $this->em()->find(VaultFolder::class, $id);
    }

    public function findByCreator(object $creator, bool $includeDeleted = false): array
    {
        $qb = $this->em()->createQueryBuilder()
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
        return $this->em()->createQueryBuilder()
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
