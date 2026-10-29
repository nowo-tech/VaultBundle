<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Default extension login: load user by identifier, run the user checker, and verify password hash.
 *
 * The user checker (firewall user_checker, e.g. disabled/locked/expired accounts) runs
 * like Symfony's form login: checkPreAuth before the password, checkPostAuth after.
 */
final readonly class DefaultVaultBrowserExtensionAuthenticator implements VaultBrowserExtensionAuthenticatorInterface
{
    /**
     * Creates the authenticator backed by the host user provider, password hasher and optional user checker.
     *
     * @param UserProviderInterface<UserInterface> $userProvider
     */
    public function __construct(
        private UserProviderInterface $userProvider,
        private UserPasswordHasherInterface $passwordHasher,
        private ?UserCheckerInterface $userChecker = null,
    ) {
    }

    /**
     * Verifies the username/password pair against the user provider and password hasher.
     */
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

        try {
            $this->userChecker?->checkPreAuth($user);

            if (!$this->passwordHasher->isPasswordValid($user, $password)) {
                return VaultBrowserExtensionAuthResult::failure('Invalid credentials.');
            }

            $this->userChecker?->checkPostAuth($user);
        } catch (AccountStatusException) {
            // Same message as a bad password: do not reveal account state to the extension.
            return VaultBrowserExtensionAuthResult::failure('Invalid credentials.');
        }

        return VaultBrowserExtensionAuthResult::success($user);
    }
}
