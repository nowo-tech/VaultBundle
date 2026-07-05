<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Default extension login: load user by identifier and verify password hash.
 */
final readonly class DefaultVaultBrowserExtensionAuthenticator implements VaultBrowserExtensionAuthenticatorInterface
{
    /**
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function authenticate(string $username, string $password): VaultBrowserExtensionAuthResult
    {
        try {
            $user = $this->userProvider->loadUserByIdentifier($username);
        } catch (UserNotFoundException) {
            return VaultBrowserExtensionAuthResult::failure('Invalid credentials.');
        }

        if (!$user instanceof PasswordAuthenticatedUserInterface) {
            return VaultBrowserExtensionAuthResult::failure('User does not support password authentication.');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return VaultBrowserExtensionAuthResult::failure('Invalid credentials.');
        }

        return VaultBrowserExtensionAuthResult::success($user);
    }
}
