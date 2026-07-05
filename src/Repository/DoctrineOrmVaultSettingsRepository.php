<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\VaultBundle\Entity\VaultSettings;

final readonly class DoctrineOrmVaultSettingsRepository implements VaultSettingsRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByScope(string $scope = VaultSettings::DEFAULT_SCOPE): ?VaultSettings
    {
        return $this->entityManager->find(VaultSettings::class, $scope);
    }

    public function save(VaultSettings $settings): void
    {
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
    }
}
