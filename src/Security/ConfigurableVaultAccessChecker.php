<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Role-based default implementation of {@see VaultAccessCheckerInterface}.
 */
final readonly class ConfigurableVaultAccessChecker implements VaultAccessCheckerInterface
{
    public function __construct(
        private Security $security,
        private VaultRuntimeConfigProvider $runtimeConfig,
    ) {
    }

    public function canAccess(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['access_roles']);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['create_roles']);
    }

    public function canList(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['list_roles']);
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['delete_roles']);
    }

    private function isAdmin(): bool
    {
        foreach ($this->securityRoles()['admin_roles'] as $role) {
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

    /**
     * @return array{admin_roles: list<string>, access_roles: list<string>, create_roles: list<string>, list_roles: list<string>, delete_roles: list<string>}
     */
    private function securityRoles(): array
    {
        /* @var array{admin_roles: list<string>, access_roles: list<string>, create_roles: list<string>, list_roles: list<string>, delete_roles: list<string>} */
        return $this->runtimeConfig->get()['security'];
    }
}
