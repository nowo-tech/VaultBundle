<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

use function trim;

/**
 * Converts between the Symfony users the vault services work on and the framework-free {@see VaultUserRef} of the
 * 1.19 ports (REQ-ARCH-001 rule 3). Stateless.
 *
 * @internal
 */
final class VaultUserRefs
{
    /**
     * The reference of a user: its `getId()` as a string (null when it has none or it is blank), its identifier, its
     * role names and the user object itself (hidden in the VO). A {@see VaultRefUser} gives back its own reference.
     */
    public static function fromUser(UserInterface $user): VaultUserRef
    {
        if ($user instanceof VaultRefUser) {
            // The directory's stand-in: its own data, with the stand-in as the user object.
            $ref = $user->ref();

            return new VaultUserRef($user->getId(), $ref->identifier, $ref->roles, $user);
        }

        $id = UserIdResolver::getId($user);
        if ($id !== null && trim($id) === '') {
            $id = null;
        }

        return new VaultUserRef($id, $user->getUserIdentifier(), $user->getRoles(), $user);
    }

    /**
     * Same as {@see self::fromUser()}, null for null.
     */
    public static function fromNullableUser(?UserInterface $user): ?VaultUserRef
    {
        return $user instanceof UserInterface ? self::fromUser($user) : null;
    }

    /**
     * The Symfony user object a reference carries, or null (audit NJ-1: a reference by id alone is never trusted as a
     * user; resolve its id through {@see VaultUserRefResolver}).
     */
    public static function toUser(VaultUserRef $ref): ?UserInterface
    {
        $user = $ref->user();

        return $user instanceof UserInterface ? $user : null;
    }
}
