<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Repository\RepositoryFactory;

use function is_a;

/**
 * The repository factory of the bundle's private entity manager (audit NX, NZ-M1).
 *
 * DoctrineBundle's shared ContainerRepositoryFactory keeps one repository per manager instance for the life of the
 * process, so every replaced private manager would stay in memory (FrankenPHP worker mode, kernel reset false). This
 * factory belongs to ONE manager, so what it keeps is collected with that manager.
 *
 * - A service repository (`ServiceEntityRepositoryInterface`, for example a host entity mapped on the same
 *   configuration that a host listener loads) is resolved by the source configuration's factory: that is the
 *   container service, it is not keyed by manager and it does not leak.
 * - Any other repository is built here, with the mapped `repositoryClass` or the configuration's default one.
 *
 * @internal
 */
final class PrivateManagerRepositoryFactory implements RepositoryFactory
{
    /** @var array<class-string, EntityRepository<object>> */
    private array $repositories = [];

    /**
     * @param RepositoryFactory $source the repository factory of the source configuration (the application's)
     */
    public function __construct(
        private readonly RepositoryFactory $source,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityName
     *
     * @return EntityRepository<T>
     */
    public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
    {
        $metadata = $entityManager->getClassMetadata($entityName);
        $class    = $metadata->customRepositoryClassName;
        if ($class !== null && is_a($class, ServiceEntityRepositoryInterface::class, true)) {
            /** @var EntityRepository<T> $service */
            $service = $this->source->getRepository($entityManager, $entityName);

            return $service;
        }

        /** @var EntityRepository<T> $repository */
        // @igor-ignore - Per-manager cache: this factory belongs to one private manager and is collected with it.
        $repository = $this->repositories[$metadata->getName()] ??= $this->create($entityManager, $metadata, $class ?? $entityManager->getConfiguration()->getDefaultRepositoryClassName());

        return $repository;
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param class-string<EntityRepository<object>> $class
     *
     * @return EntityRepository<object>
     */
    private function create(EntityManagerInterface $entityManager, ClassMetadata $metadata, string $class): EntityRepository
    {
        return new $class($entityManager, $metadata);
    }
}
