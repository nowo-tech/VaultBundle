<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The value a query binds to a user column for a user id (2.5.0, audit ZCM-L3): with the id mapping the id itself;
 * with the relation mapping the reference the user reference resolver builds for the user the directory finds
 * (`getReference(user_class, id)`), never the directory's stand-in object, which is not an entity. An id the directory
 * does not know, or a user the resolver refuses, falls back to the id (Doctrine compares it with the user's key), so
 * an erasure or an export still reaches the rows of a deleted user.
 *
 * Stateless.
 *
 * @internal
 */
final class VaultUserBinding
{
    /**
     * The value to bind for the user id.
     */
    public static function of(string $userId, ?VaultUserLocatorGate $locator, ?VaultUserReferenceResolverInterface $users): object|string
    {
        if ($users?->mapsById() === true) {
            return $userId;
        }

        $user = $locator?->find($userId);
        if (!$user instanceof UserInterface) {
            return $userId;
        }
        if (!$users instanceof VaultUserReferenceResolverInterface) {
            return $user;
        }

        try {
            return $users->reference($user);
        } catch (AccessDeniedException) {
            return $userId;
        }
    }
}
