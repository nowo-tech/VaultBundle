<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Security;

use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_starts_with;

/**
 * Test firewall user checker: accounts whose identifier starts with "disabled" are disabled.
 */
final class DisabledAccountUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (str_starts_with($user->getUserIdentifier(), 'disabled')) {
            throw new DisabledException('Account is disabled.');
        }
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
    }
}
