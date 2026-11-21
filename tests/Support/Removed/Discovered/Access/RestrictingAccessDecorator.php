<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Removed\Discovered\Access;

use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.x host decorator of the access port removed in 2.0 (it narrows access), registered by resource discovery with
 * `#[AsDecorator]`. It cannot be loaded: the build must fail instead of dropping it, which would widen access.
 * Excluded from static analysis (rector.php); never instantiated.
 */
#[AsDecorator(VaultAccessCheckerInterface::class)]
final class RestrictingAccessDecorator implements VaultAccessCheckerInterface
{
    public function __construct(#[AutowireDecorated] private readonly VaultAccessCheckerInterface $inner)
    {
    }

    public function canAccess(?UserInterface $user = null): bool
    {
        return $user !== null && $this->inner->canAccess($user);
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        return false;
    }

    public function canList(?UserInterface $user = null): bool
    {
        return $this->inner->canList($user);
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        return false;
    }
}
