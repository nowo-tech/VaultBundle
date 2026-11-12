<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Repository\RepositoryFactory;

use function count;
use function spl_object_id;

/**
 * Stands for DoctrineBundle's ContainerRepositoryFactory: keeps one repository per manager instance for the life of
 * the process (audit NX).
 */
final class RetainingRepositoryFactory implements RepositoryFactory
{
    /** @var array<int, array<string, EntityRepository<object>>> */
    private array $repositories = [];

    /**
     * @template T of object
     *
     * @param class-string<T> $entityName
     *
     * @return EntityRepository<T>
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        /** @var EntityRepository<T> $repository */
        $repository = $this->repositories[spl_object_id($entityManager)][$entityName] ??= new EntityRepository($entityManager, $entityManager->getClassMetadata($entityName));

        return $repository;
    }

    /**
     * How many manager instances this factory holds repositories for.
     */
    public function managers(): int
    {
        return count($this->repositories);
    }
}
