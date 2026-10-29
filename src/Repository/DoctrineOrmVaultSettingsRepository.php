<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultSettings;

/**
 * Doctrine ORM implementation of the runtime settings repository.
 */
final readonly class DoctrineOrmVaultSettingsRepository implements VaultSettingsRepositoryInterface
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
     * Finds the settings row of a scope, or null.
     */
    public function findByScope(string $scope = VaultSettings::DEFAULT_SCOPE): ?VaultSettings
    {
        /* @var VaultSettings|null */
        return $this->em()->createQueryBuilder()
            ->select('s')
            ->from(VaultSettings::class, 's')
            ->where('s.scope = :scope')
            ->setParameter('scope', $scope)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * Persists and flushes the settings.
     */
    public function save(VaultSettings $settings): void
    {
        $this->em()->persist($settings);
        $this->em()->flush();
    }
}
