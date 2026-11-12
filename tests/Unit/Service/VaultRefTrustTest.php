<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Event\VaultItemListQueryEvent;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Audit NJ-1 (1.19.0): a reference given by id alone carries no trust. The vault looks the id up again through the
 * user locator port and uses that answer's identity and roles (never the caller's), refuses an unknown id or an answer
 * for another id, and the stand-in user exists only for directory answers.
 */
final class VaultRefTrustTest extends TestCase
{
    public const ADMIN = 'u-admin';

    #[Test]
    public function aForgedAuthenticatorResultIsResolvedAgainAndItsRolesAreIgnored(): void
    {
        $seen    = [];
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturnCallback(static function (?UserInterface $user) use (&$seen): bool {
            $seen[] = $user?->getRoles() ?? [];

            return true;
        });

        // Scenario A: an authenticator (or a listener) answers with an admin id and admin roles of its own.
        $token = $this->service(VaultUserRef::ofId(self::ADMIN, 'forged@example.com', ['ROLE_VAULT_ADMIN']), $checker)->login('x', 'y');
        self::assertNotNull($token, 'the id is a known vault user');
        self::assertSame([['ROLE_USER']], $seen, 'the roles come from the directory, never from the reference');

        self::assertNull($this->service(VaultUserRef::ofId('u-unknown', 'x', ['ROLE_VAULT_ADMIN']), $checker)->login('x', 'y'), 'an id the directory does not know gets no token');
    }

    #[Test]
    public function aUserTheDirectoryRefusesGetsNoToken(): void
    {
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);

        // Scenario B: the directory answers null for a disabled, locked or foreign user.
        self::assertNull($this->service(VaultUserRef::ofId('u-disabled'), $checker)->login('x', 'y'));
    }

    #[Test]
    public function aUserObjectStaysSubjectToTheClassPolicy(): void
    {
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);
        $foreign = new class implements UserInterface {
            public function getId(): string
            {
                return 'u-admin';
            }

            public function getUserIdentifier(): string
            {
                return 'admin@other.example';
            }

            public function getRoles(): array
            {
                return ['ROLE_ADMIN'];
            }

            public function eraseCredentials(): void
            {
            }
        };

        self::assertNull($this->service(new VaultUserRef('u-admin', 'x', [], $foreign), $checker)->login('x', 'y'), 'another identity domain, as in 1.18');
    }

    #[Test]
    public function theListSubjectIsResolvedAgainToo(): void
    {
        $resolver = new VaultUserRefResolver($this->locator());
        $event    = new VaultItemListQueryEvent(new TestUser('u-1'), new TestUser('u-1'), refResolver: $resolver->resolve(...));
        $event->setListSubjectRef(VaultUserRef::ofId(self::ADMIN, 'x', ['ROLE_VAULT_ADMIN']));
        self::assertInstanceOf(VaultRefUser::class, $event->getListSubject());
        self::assertSame(['ROLE_USER'], $event->getListSubject()->getRoles());

        $this->expectException(InvalidArgumentException::class);
        $event->setListSubjectRef(VaultUserRef::ofId('u-unknown'));
    }

    #[Test]
    public function theResolverRefusesAnAnswerForAnotherId(): void
    {
        $locator = $this->createMock(VaultUserLocatorInterface::class);
        $locator->method('find')->willReturn(new TestUser('U-ADMIN'));
        self::assertNull((new VaultUserRefResolver($locator))->resolve(VaultUserRef::ofId(self::ADMIN)));
        self::assertNull((new VaultUserRefResolver())->resolve(VaultUserRef::ofId(self::ADMIN)), 'no locator: no trust');
        self::assertNull((new VaultUserRefResolver($locator))->resolve(new VaultUserRef(null)));
        $user = new TestUser('u-9');
        self::assertSame($user, (new VaultUserRefResolver($locator))->resolve(new VaultUserRef('u-9', '', [], $user)));
    }

    private function service(VaultUserRef $answer, VaultAccessCheckerInterface $checker): VaultBrowserExtensionAuthService
    {
        $authenticator = $this->createMock(VaultBrowserExtensionAuthenticatorInterface::class);
        $authenticator->method('authenticate')->willReturn(VaultBrowserExtensionAuthResult::authenticated($answer));
        $tokens = $this->createMock(VaultExtensionTokenRepositoryInterface::class);
        $tokens->method('save')->willReturnCallback(static function (VaultExtensionToken $token): void {
        });
        $users = new DoctrineVaultUserReferenceResolver(TestUser::class, new VaultUserClassPolicy(TestUser::class, [], VaultUserMapping::ID), VaultUserMapping::ID);

        return new VaultBrowserExtensionAuthService($authenticator, $tokens, new EventDispatcher(), 3600, $checker, users: $users, userLocator: $this->locator(), refs: new VaultUserRefResolver($this->locator()));
    }

    private function locator(): VaultUserLocatorGate
    {
        $directory = new class implements VaultUserDirectoryInterface {
            public function findUser(string $id): ?VaultUserRef
            {
                // The host directory: known, enabled users only; a disabled one is answered null.
                return $id === VaultRefTrustTest::ADMIN ? VaultUserRef::ofId($id, 'admin@example.com', ['ROLE_USER']) : null;
            }
        };

        return new VaultUserLocatorGate(VaultPortMode::POLICY, null, $directory);
    }
}
