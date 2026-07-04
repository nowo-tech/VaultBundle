<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

/**
 * Resolves user identifiers for ownership checks.
 */
final class UserIdResolver
{
    public static function getId(object $user): ?string
    {
        if (!method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();

        if ($id === null) {
            return null;
        }

        return (string) $id;
    }

    public static function isSameUser(object $left, object $right): bool
    {
        $leftId  = self::getId($left);
        $rightId = self::getId($right);

        return $leftId !== null && $rightId !== null && $leftId === $rightId;
    }
}
