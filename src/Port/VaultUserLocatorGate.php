<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How the bundle loads a user by id: the host's {@see VaultUserDirectoryInterface} (the application's alias of the
 * interface or the built-in {@see VaultUserLocator}). Only an answer for exactly the
 * requested id is kept; without a user object it becomes the vault's stand-in carrying the directory's identifier and
 * roles.
 *
 * Since 2.0 the only interface asked (the deprecated `VaultUserLocatorInterface` was removed).
 *
 * @internal
 */
final readonly class VaultUserLocatorGate
{
    /**
     * Wraps the user directory the build picked.
     */
    public function __construct(private VaultUserDirectoryInterface $policy)
    {
    }

    /**
     * User with exactly that id, or null.
     */
    public function find(string $id): ?UserInterface
    {
        $ref = $this->policy->findUser($id);
        // Audit NJ-1: an answer for another id (byte for byte) is refused.
        if (!$ref instanceof VaultUserRef || $ref->id !== $id) {
            return null;
        }

        $user = VaultUserRefs::toUser($ref);
        if ($user instanceof UserInterface) {
            return UserIdResolver::getId($user) === $id ? $user : null;
        }

        return VaultRefUser::fromDirectory($ref);
    }
}
