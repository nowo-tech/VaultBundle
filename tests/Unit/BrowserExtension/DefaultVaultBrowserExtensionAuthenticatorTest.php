<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\BrowserExtension;

use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\CredentialsExpiredException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\InMemoryUserChecker;
use Symfony\Component\Security\Core\User\InMemoryUserProvider;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class DefaultVaultBrowserExtensionAuthenticatorTest extends TestCase
{
    public function testDisabledUserIsRejectedBeforePasswordCheck(): void
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::never())->method('isPasswordValid');

        $authenticator = new DefaultVaultBrowserExtensionAuthenticator(
            $this->provider(enabled: false),
            $hasher,
            new InMemoryUserChecker(),
        );

        $result = $authenticator->authenticate('alice@example.com', 'secret');

        self::assertFalse($result->isSuccess());
        self::assertSame('Invalid credentials.', $result->getFailureReason());
    }

    public function testPostAuthCheckerFailureRejectsLogin(): void
    {
        $checker = $this->createMock(UserCheckerInterface::class);
        $checker->expects(self::once())->method('checkPreAuth');
        $checker->expects(self::once())->method('checkPostAuth')
            ->willThrowException(new CredentialsExpiredException());

        $authenticator = new DefaultVaultBrowserExtensionAuthenticator($this->provider(), $this->hasher(true), $checker);

        self::assertFalse($authenticator->authenticate('alice@example.com', 'secret')->isSuccess());
    }

    public function testPostAuthCheckIsSkippedForWrongPassword(): void
    {
        $checker = $this->createMock(UserCheckerInterface::class);
        $checker->expects(self::never())->method('checkPostAuth');

        $authenticator = new DefaultVaultBrowserExtensionAuthenticator($this->provider(), $this->hasher(false), $checker);

        self::assertFalse($authenticator->authenticate('alice@example.com', 'wrong')->isSuccess());
    }

    public function testEnabledUserPassesCheckerAndPassword(): void
    {
        $authenticator = new DefaultVaultBrowserExtensionAuthenticator($this->provider(), $this->hasher(true), new InMemoryUserChecker());

        $result = $authenticator->authenticate('alice@example.com', 'secret');

        self::assertTrue($result->isSuccess());
        self::assertSame('alice@example.com', $result->getUser()?->getUserIdentifier());
    }

    public function testWorksWithoutUserChecker(): void
    {
        $authenticator = new DefaultVaultBrowserExtensionAuthenticator($this->provider(), $this->hasher(true));

        self::assertTrue($authenticator->authenticate('alice@example.com', 'secret')->isSuccess());
        self::assertFalse($authenticator->authenticate('nobody@example.com', 'secret')->isSuccess());
    }

    public function testRejectsUsersWithoutPasswordSupport(): void
    {
        $user     = $this->createMock(UserInterface::class);
        $provider = $this->createMock(UserProviderInterface::class);
        $provider->method('loadUserByIdentifier')->willReturnCallback(
            static fn (string $id): UserInterface => $id === 'alice' ? $user : throw new UserNotFoundException(),
        );

        $authenticator = new DefaultVaultBrowserExtensionAuthenticator($provider, $this->hasher(true), new InMemoryUserChecker());

        self::assertFalse($authenticator->authenticate('alice', 'secret')->isSuccess());
    }

    private function provider(bool $enabled = true): InMemoryUserProvider
    {
        return new InMemoryUserProvider([
            'alice@example.com' => ['password' => 'hash', 'roles' => ['ROLE_USER'], 'enabled' => $enabled],
        ]);
    }

    private function hasher(bool $valid): UserPasswordHasherInterface
    {
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturnCallback(
            static fn (InMemoryUser $user, string $password): bool => $valid,
        );

        return $hasher;
    }
}
