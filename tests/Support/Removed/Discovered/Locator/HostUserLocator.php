<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Support\Removed\Discovered\Locator;

use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.x host user lookup aliased to the user locator port removed in 2.0 with `#[AsAlias]`, registered by resource
 * discovery. It cannot be loaded: the build must fail instead of silently falling back to the built-in lookup.
 * Excluded from static analysis (rector.php); never instantiated.
 */
#[AsAlias(VaultUserLocatorInterface::class)]
final class HostUserLocator implements VaultUserLocatorInterface
{
    public function find(string $id): ?UserInterface
    {
        return null;
    }
}
