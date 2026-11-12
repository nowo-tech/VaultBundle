<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The scope membership the bundle uses (1.19.0, behind the per-request memo): the host's
 * {@see VaultScopeAccessProviderInterface}, the deprecated {@see VaultScopeAccessResolverInterface} (exactly as in
 * 1.18), or both, where the weaker answer wins (fail closed): not a member when either says so, the lower baseline
 * (none when either has none), each flag only when both set it.
 *
 * @internal
 */
final readonly class VaultScopeAccessGate implements VaultScopeAccessResolverInterface
{
    private const RANK = [VaultPermission::Read->value => 1, VaultPermission::Write->value => 2, VaultPermission::Admin->value => 3];

    public function __construct(
        private string $mode,
        private ?VaultScopeAccessResolverInterface $legacy = null,
        private ?VaultScopeAccessProviderInterface $policy = null,
    ) {
        VaultPortMode::assert($mode, $legacy, $policy, 'scopes.access_resolver');
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        $legacy = null;
        if ($this->mode !== VaultPortMode::POLICY) {
            /** @var VaultScopeAccessResolverInterface $resolver asserted in the constructor */
            $resolver = $this->legacy;
            $legacy   = $resolver->resolve($user, $scope);
            if ($this->mode === VaultPortMode::LEGACY || !$legacy instanceof VaultScopeAccess) {
                return $legacy;
            }
        }

        /** @var VaultScopeAccessProviderInterface $provider asserted in the constructor */
        $provider = $this->policy;
        $access   = $provider->scopeAccessOf(VaultUserRefs::fromUser($user), $scope);
        if (!$legacy instanceof VaultScopeAccess || !$access instanceof VaultScopeAccess) {
            return $access;
        }

        return new VaultScopeAccess(
            $this->lower($legacy->baseline, $access->baseline),
            $legacy->catalogue && $access->catalogue,
            $legacy->health && $access->health,
            $legacy->create && $access->create,
        );
    }

    private function lower(?VaultPermission $left, ?VaultPermission $right): ?VaultPermission
    {
        if (!$left instanceof VaultPermission || !$right instanceof VaultPermission) {
            return null;
        }

        return self::RANK[$left->value] <= self::RANK[$right->value] ? $left : $right;
    }
}
