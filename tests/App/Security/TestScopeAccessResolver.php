<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Security;

use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Scope resolver of the scopes test kernel: membership is a static map the tests set (user id => scope => access).
 */
final class TestScopeAccessResolver implements VaultScopeAccessResolverInterface
{
    /** @var array<string, array<string, VaultScopeAccess>> */
    public static array $members = [];

    /** Number of resolve() calls (memoization checks). */
    public static int $calls = 0;

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        ++self::$calls;

        return self::$members[(string) UserIdResolver::getId($user)][$scope] ?? null;
    }
}
