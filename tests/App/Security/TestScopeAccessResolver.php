<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Security;

use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Scope resolver of the scopes test kernel: membership is a static map the tests set (user id => scope => access).
 */
final class TestScopeAccessResolver implements VaultScopeAccessProviderInterface
{
    /** @var array<string, array<string, VaultScopeAccess>> */
    public static array $members = [];

    /** Number of scopeAccessOf() calls (memoization checks). */
    public static int $calls = 0;

    public function scopeAccessOf(VaultUserRef $user, string $scope): ?VaultScopeAccess
    {
        ++self::$calls;

        return self::$members[(string) $user->id][$scope] ?? null;
    }
}
