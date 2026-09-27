<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Resolves the EntityManager per call and replaces it when a previous flush closed it,
 * so a long-running worker recovers without relying on the kernel.reset services resetter.
 *
 * Requires the using class to declare `$entityManager`, `?ManagerRegistry $registry` and `?string $managerName`.
 *
 * @internal
 */
trait ResolvesEntityManagerTrait
{
    private function em(): EntityManagerInterface
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }
}
