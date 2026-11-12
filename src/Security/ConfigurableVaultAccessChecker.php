<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Role-based default implementation of {@see VaultAccessPolicyInterface} and of the deprecated
 * {@see VaultAccessCheckerInterface}: both give the same answer for the same user (1.19.0; the reference's user object,
 * or the vault's stand-in for a reference given by id, whose role names are then the ones checked).
 */
final readonly class ConfigurableVaultAccessChecker implements VaultAccessPolicyInterface, VaultAccessCheckerInterface
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

    public function allowsAccess(?VaultUserRef $user = null): bool
    {
        return self::known($user) && $this->canAccess(self::user($user));
    }

    public function allowsCreate(?VaultUserRef $user = null): bool
    {
        return self::known($user) && $this->canCreate(self::user($user));
    }

    public function allowsList(?VaultUserRef $user = null): bool
    {
        return self::known($user) && $this->canList(self::user($user));
    }

    public function allowsRevoke(?VaultUserRef $user = null): bool
    {
        return self::known($user) && $this->canRevoke(self::user($user));
    }

    /**
     * False for a reference with neither a user object nor an id (fail closed: it never stands for the token user).
     */
    private static function known(?VaultUserRef $user): bool
    {
        return !$user instanceof VaultUserRef || VaultUserRefs::toUser($user) instanceof UserInterface;
    }

    /**
     * The user a reference stands for (null for null: the request's own token is checked).
     */
    private static function user(?VaultUserRef $user): ?UserInterface
    {
        return $user instanceof VaultUserRef ? VaultUserRefs::toUser($user) : null;
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
