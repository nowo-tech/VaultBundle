<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Loads a host user by id (`nowo_vault.user_class`), e.g. to check that a user grantee belongs to a scope (1.16.0).
 */
final readonly class VaultUserLocator
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
     * The user with this id, or null (unknown id, or an id of the wrong type).
     */
    public function find(string $id): ?UserInterface
    {
        if ($id === '') {
            return null;
        }

        try {
            $user = $this->registry->getManager($this->managerName)->find($this->userClass, $id);
        } catch (Throwable) {
            return null;
        }

        return $user instanceof UserInterface ? $user : null;
    }
}
