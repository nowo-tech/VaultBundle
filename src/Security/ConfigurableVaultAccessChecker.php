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
    /**
     * Creates the checker with Symfony Security and the runtime configuration provider.
     */
    public function __construct(
        private Security $security,
        private VaultRuntimeConfigProvider $runtimeConfig,
    ) {
    }

    /**
     * Whether the user holds an admin role or one of the configured access_roles.
     */
    public function canAccess(?UserInterface $user = null): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['access_roles'], $user);
    }

    /**
     * Whether the user holds an admin role or one of the configured create_roles.
     */
    public function canCreate(?UserInterface $user = null): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['create_roles'], $user);
    }

    /**
     * Whether the user holds an admin role or one of the configured list_roles.
     */
    public function canList(?UserInterface $user = null): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['list_roles'], $user);
    }

    /**
     * Whether the user holds an admin role or one of the configured delete_roles.
     */
    public function canRevoke(?UserInterface $user = null): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->hasAnyRole($this->securityRoles()['delete_roles'], $user);
    }

    private function isAdmin(?UserInterface $user): bool
    {
        return $this->hasAnyRole($this->securityRoles()['admin_roles'], $user);
    }

    /**
     * @param list<string> $roles
     * @param UserInterface|null $user Checked instead of the request token when it is not the current user (browser
     *                                 extension Bearer requests have no security token)
     */
    private function hasAnyRole(array $roles, ?UserInterface $user): bool
    {
        $forUser = $user instanceof UserInterface && $user !== $this->security->getUser();
        foreach ($roles as $role) {
            if ($forUser ? $this->security->isGrantedForUser($user, $role) : $this->security->isGranted($role)) {
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
