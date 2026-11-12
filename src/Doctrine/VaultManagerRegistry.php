<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function str_starts_with;
use function strrpos;
use function substr;

/**
 * The Doctrine registry the vault repositories and services see (1.19.0, REQ-ARCH-001 rule 6, private entity manager
 * only): the vault's entity manager name (`database.entity_manager`) and the vault entity classes resolve to
 * {@see VaultEntityManager}, everything else to the application's registry unchanged. Resetting the vault's manager
 * drops the private one; the application's managers are never reset by the bundle.
 *
 * @internal
 */
final readonly class VaultManagerRegistry implements ManagerRegistry
{
    public const ENTITY_NAMESPACE = 'Nowo\\VaultBundle\\Entity';

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry
     * @param VaultEntityManager $entityManager The bundle's private manager
     * @param string $managerName `nowo_vault.database.entity_manager` (the source of the private manager)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private VaultEntityManager $entityManager,
        private string $managerName = 'default',
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    public function getManager(?string $name = null): ObjectManager
    {
        if ($this->isVaultManager($name)) {
            return $this->entityManager;
        }

        return $this->registry->getManager($name);
    }

    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    /**
     * The vault's manager: drops the private manager (a fresh one is created on the next call). Any other name: resets
     * that application manager on the application's behalf only (the bundle itself never asks for it).
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        if ($this->isVaultManager($name)) {
            $this->entityManager->reset();

            return $this->entityManager;
        }

        return $this->registry->resetManager($name);
    }

    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if (($persistentManagerName === null || $this->isVaultManager($persistentManagerName)) && self::isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject); // @phpstan-ignore return.type (the repository of that class)
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        if (self::isBundleEntity($class)) {
            return $this->entityManager;
        }

        return $this->registry->getManagerForClass($class);
    }

    private function isVaultManager(?string $name): bool
    {
        return ($name ?? $this->registry->getDefaultManagerName()) === $this->managerName;
    }

    /**
     * Whether a class (or a Doctrine proxy class of it, `…\__CG__\…`) is a vault entity.
     */
    public static function isBundleEntity(string $class): bool
    {
        return str_starts_with(self::realClass($class), self::ENTITY_NAMESPACE . '\\');
    }

    /**
     * The entity class of a Doctrine proxy class name (`…\__CG__\App\Entity\X` → `App\Entity\X`), or the class itself.
     */
    public static function realClass(string $class): string
    {
        $marker = strrpos($class, '\\__CG__\\');

        return $marker !== false ? substr($class, $marker + 8) : $class;
    }
}
