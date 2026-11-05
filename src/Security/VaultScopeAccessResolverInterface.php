<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Host hook (1.16.0): what a scope is, who belongs to it and with which baseline permission. The bundle calls it on
 * every request (memoized per request only), so a user who leaves a scope loses access at once.
 */
interface VaultScopeAccessResolverInterface
{
    /**
     * The user's standing in a scope, or null when the user is not a member (no access to anything in the scope,
     * grants included). Called at most once per (user, scope) per request.
     */
    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess;
}
