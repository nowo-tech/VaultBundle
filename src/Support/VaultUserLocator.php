<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Loads a host user by id (`nowo_vault.user_class`), e.g. to check that a user grantee belongs to a scope (1.16.0).
 *
 * 1.17.0: reads through the entity manager that maps `user_class` (the vault's one when none does).
 * 1.19.0: also the built-in {@see VaultUserDirectoryInterface} (same lookup, the user as a {@see VaultUserRef}).
 */
final readonly class VaultUserLocator implements VaultUserDirectoryInterface, VaultUserLocatorInterface
{
    /**
     * @param class-string $userClass
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $userClass,
        private ?string $managerName = null,
    ) {
    }

    /**
     * The user with this id as a reference (its user object included), or null.
     */
    public function findUser(string $id): ?VaultUserRef
    {
        $user = $this->find($id);

        return $user instanceof UserInterface ? VaultUserRefs::fromUser($user) : null;
    }

    /**
     * The user with this id, or null (unknown id, or an id of the wrong type).
     */
    public function find(string $id): ?UserInterface
    {
        if ($id === '') {
            return null;
        }

        try {
            $manager = $this->registry->getManagerForClass($this->userClass) ?? $this->registry->getManager($this->managerName);
            $user    = $manager->find($this->userClass, $id);
        } catch (Throwable) {
            return null;
        }

        return $user instanceof UserInterface ? $user : null;
    }
}
