<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Role-based default implementation of {@see VaultAccessCheckerInterface}.
 */
final readonly class ConfigurableVaultAccessChecker implements VaultAccessCheckerInterface
{
    /**
     * @param list<string> $adminRoles
     * @param list<string> $accessRoles
     * @param list<string> $createRoles
     * @param list<string> $listRoles
     * @param list<string> $deleteRoles
     */
    public function __construct(
        private Security $security,
        private array $adminRoles,
        private array $accessRoles,
        private array $createRoles,
        private array $listRoles,
        private array $deleteRoles,
    ) {
    }

    public function canAccess(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->accessRoles);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->createRoles);
    }

    public function canList(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->listRoles);
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->deleteRoles);
    }

    private function isAdmin(): bool
    {
        foreach ($this->adminRoles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $roles
     */
    private function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->security->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}
