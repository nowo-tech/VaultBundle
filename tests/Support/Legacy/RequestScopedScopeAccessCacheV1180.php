<?php

declare(strict_types=1);

// Literal copy of v1.18.0 src/Security/RequestScopedScopeAccessCache.php (equivalence reference of 1.19.0): only the namespace, the class name and the
// imports differ. Never edit the logic.

namespace Nowo\VaultBundle\Tests\Support\Legacy;

use Nowo\VaultBundle\EventListener\VaultRequestBoundaryListener;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_key_exists;

/**
 * Memoizes {@see VaultScopeAccessResolverInterface::resolve()} per (user, scope) for one request, so membership is
 * re-evaluated on every request. The bundle clears it itself at the main `kernel.request`, `kernel.terminate`,
 * `console.terminate` and Messenger worker boundaries ({@see VaultRequestBoundaryListener},
 * 1.17.1), so it stays correct in FrankenPHP worker mode with kernel reset false; `kernel.reset` also clears it.
 * A malformed scope key is never passed to the host resolver: it resolves to null.
 */
final class RequestScopedScopeAccessCacheV1180 implements ResetInterface
{
    /** @var array<string, VaultScopeAccess|null> */
    private array $memo = [];

    /**
     * Creates the cache around the host resolver.
     */
    public function __construct(
        private readonly VaultScopeAccessResolverInterface $resolver,
    ) {
    }

    /**
     * The user's standing in the scope (memoized for this request), or null for a non-member or a malformed key.
     */
    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        if (!VaultOwner::isValidScope($scope)) {
            return null;
        }

        $key = (UserIdResolver::getId($user) ?? 'identifier:' . $user->getUserIdentifier()) . "\0" . $scope;
        if (!array_key_exists($key, $this->memo)) {
            // @igor-ignore - Request-scoped memo, cleared by VaultRequestBoundaryListener at every boundary (and kernel.reset).
            $this->memo[$key] = $this->resolver->resolve($user, $scope);
        }

        return $this->memo[$key];
    }

    /**
     * Forgets every memoized answer (end of request).
     */
    public function reset(): void
    {
        // @igor-ignore - ResetInterface: clears the request-scoped memo.
        $this->memo = [];
    }
}
