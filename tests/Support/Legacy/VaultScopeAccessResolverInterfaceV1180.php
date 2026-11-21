<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use Nowo\VaultBundle\Security\VaultScopeAccess;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host hook (1.16.0): what a scope is, who belongs to it and with which baseline permission. The bundle calls it on
 * every request (memoized per request only), so a user who leaves a scope loses access at once.
 *
 * Test copy of the v1.18.0 interface (removed in 2.0), for the equivalence reference chain only.
 */
interface VaultScopeAccessResolverInterfaceV1180
{
    /**
     * The user's standing in a scope, or null when the user is not a member (no access to anything in the scope,
     * grants included). Called at most once per (user, scope) per request.
     */
    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess;
}
