<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Entity\VaultSettings;

final readonly class DoctrineOrmVaultSettingsRepository implements VaultSettingsRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

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

    public function save(VaultSettings $settings): void
    {
        $this->em()->persist($settings);
        $this->em()->flush();
    }
}
