<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Event\VaultBrowserExtensionAuthEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class VaultBrowserExtensionAuthServiceTest extends TestCase
{
    public function testLoginIssuesHashedToken(): void
    {
        $user   = new TestUser('1', 'alice@example.com');
        $saved  = null;
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (VaultExtensionToken $token) use (&$saved): void {
                $saved = $token;
            });

        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(VaultBrowserExtensionAuthResult::success($user));

        $service = new VaultBrowserExtensionAuthService(
            $authenticator,
            $tokens,
            new EventDispatcher(),
            3600,
        );

        $result = $service->login('alice@example.com', 'secret');

        self::assertIsArray($result);
        self::assertNotSame('', $result['token']);
        self::assertInstanceOf(VaultExtensionToken::class, $saved);
        self::assertSame(
            VaultBrowserExtensionAuthService::hashToken($result['token']),
            $saved->getTokenHash(),
        );
    }

    public function testResolveUserDebouncesLastUsedUpdates(): void
    {
        $user   = new TestUser('1');
        $entity = new VaultExtensionToken(
            VaultBrowserExtensionAuthService::hashToken('plain-token'),
            new DateTimeImmutable('+1 hour'),
            $user,
        );
        $entity->touch();

        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('findValidByTokenHash')->willReturn($entity);
        $tokens->expects(self::never())->method('save');

        $service = new VaultBrowserExtensionAuthService(
            $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class),
            $tokens,
            $this->createMock(EventDispatcherInterface::class),
            3600,
        );

        self::assertSame($user, $service->resolveUser('plain-token'));
    }

    public function testLoginReturnsNullOnFailure(): void
    {
        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(VaultBrowserExtensionAuthResult::failure('invalid'));

        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->expects(self::never())->method('save');

        $service = new VaultBrowserExtensionAuthService(
            $authenticator,
            $tokens,
            new EventDispatcher(),
            3600,
        );

        self::assertNull($service->login('alice@example.com', 'wrong'));
    }

    public function testLogoutRemovesValidToken(): void
    {
        $user   = new TestUser('1');
        $entity = new VaultExtensionToken(
            VaultBrowserExtensionAuthService::hashToken('logout-token'),
            new DateTimeImmutable('+1 hour'),
            $user,
        );

        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('findValidByTokenHash')->willReturn($entity);
        $tokens->expects(self::once())->method('remove')->with($entity);

        $service = new VaultBrowserExtensionAuthService(
            $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class),
            $tokens,
            $this->createMock(EventDispatcherInterface::class),
            3600,
        );

        $service->logout('logout-token');
    }

    public function testLoginUsesEventResultWhenHandled(): void
    {
        $user = new TestUser('1', 'listener@example.com');

        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->expects(self::never())->method('authenticate');

        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->expects(self::once())->method('save');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            VaultEvents::BROWSER_EXTENSION_AUTH,
            static function (VaultBrowserExtensionAuthEvent $event) use ($user): void {
                $event->setResult(VaultBrowserExtensionAuthResult::success($user));
            },
        );

        $service = new VaultBrowserExtensionAuthService(
            $authenticator,
            $tokens,
            $dispatcher,
            3600,
        );

        $result = $service->login('listener@example.com', 'secret');

        self::assertIsArray($result);
        self::assertNotSame('', $result['token']);
    }

    public function testLoginReturnsNullWhenSuccessResultHasNoUser(): void
    {
        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn($this->createSuccessResultWithoutUser());

        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->expects(self::never())->method('save');

        $service = new VaultBrowserExtensionAuthService(
            $authenticator,
            $tokens,
            new EventDispatcher(),
            3600,
        );

        self::assertNull($service->login('alice@example.com', 'secret'));
    }

    private function createSuccessResultWithoutUser(): VaultBrowserExtensionAuthResult
    {
        $ref      = new ReflectionClass(VaultBrowserExtensionAuthResult::class);
        $instance = $ref->newInstanceWithoutConstructor();

        foreach ([
            'success'       => true,
            'user'          => null,
            'failureReason' => null,
        ] as $property => $value) {
            $prop = $ref->getProperty($property);
            $prop->setValue($instance, $value);
        }

        return $instance;
    }

    public function testLoginIsRefusedWithoutVaultAccess(): void
    {
        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(VaultBrowserExtensionAuthResult::success(new TestUser('1')));
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->expects(self::never())->method('save');
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(false);

        $service = new VaultBrowserExtensionAuthService($authenticator, $tokens, new EventDispatcher(), 3600, $checker);

        self::assertNull($service->login('alice@example.com', 'secret'));
    }

    public function testResolveUserRevokesAllTokensWhenAccessWasRemoved(): void
    {
        $user   = new TestUser('1');
        $entity = new VaultExtensionToken(VaultBrowserExtensionAuthService::hashToken('t1'), new DateTimeImmutable('+1 hour'), $user);
        $other  = new VaultExtensionToken(VaultBrowserExtensionAuthService::hashToken('t2'), new DateTimeImmutable('+1 hour'), $user);

        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('findValidByTokenHash')->willReturn($entity);
        $tokens->method('findByUser')->with($user)->willReturn([$entity, $other]);
        $tokens->expects(self::exactly(2))->method('remove');
        $tokens->expects(self::never())->method('save');
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canAccess')->with($user)->willReturn(false);

        $service = new VaultBrowserExtensionAuthService(
            $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class),
            $tokens,
            new EventDispatcher(),
            3600,
            $checker,
        );

        self::assertNull($service->resolveUser('t1'));
    }

    public function testResolveUserRejectsAccountRefusedByUserChecker(): void
    {
        $user   = new TestUser('1');
        $entity = new VaultExtensionToken(VaultBrowserExtensionAuthService::hashToken('t1'), new DateTimeImmutable('+1 hour'), $user);
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('findValidByTokenHash')->willReturn($entity);
        $tokens->method('findByUser')->willReturn([$entity]);
        $tokens->expects(self::once())->method('remove')->with($entity);
        $userChecker = $this->createMock(UserCheckerInterface::class);
        $userChecker->method('checkPreAuth')->willThrowException(new DisabledException());
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);

        $service = new VaultBrowserExtensionAuthService(
            $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class),
            $tokens,
            new EventDispatcher(),
            3600,
            $checker,
            $userChecker,
        );

        self::assertNull($service->resolveUser('t1'));
    }

    public function testResolveUserIgnoresTokenWithoutSecurityUser(): void
    {
        $entity = new VaultExtensionToken(VaultBrowserExtensionAuthService::hashToken('t1'), new DateTimeImmutable('+1 hour'), new stdClass());
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('findValidByTokenHash')->willReturn($entity);
        $tokens->expects(self::never())->method('save');

        $service = new VaultBrowserExtensionAuthService(
            $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class),
            $tokens,
            new EventDispatcher(),
            3600,
        );

        self::assertNull($service->resolveUser('t1'));
    }
}
