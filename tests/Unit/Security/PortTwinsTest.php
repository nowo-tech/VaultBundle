<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Security\NullVaultScopeAccessResolver;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * 1.19.0 built-ins behind the framework-free ports: the same answers as through the deprecated interfaces, a reference
 * by id is checked with its own role names, and a reference with neither a user nor an id never stands for the
 * request's user.
 */
final class PortTwinsTest extends TestCase
{
    #[Test]
    public function theRoleBasedCheckerAnswersTheSameThroughBothInterfaces(): void
    {
        $token    = new TestUser('1', 'token@example.com', ['ROLE_VAULT_USER']);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($token);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, $token->getRoles(), true));
        $security->method('isGrantedForUser')->willReturnCallback(static fn (UserInterface $user, string $role): bool => in_array($role, $user->getRoles(), true));
        $checker = new ConfigurableVaultAccessChecker($security, new VaultRuntimeConfigProvider(VaultRuntimeConfigFactory::baseline(['security' => [
            'admin_roles'  => ['ROLE_VAULT_ADMIN'],
            'access_roles' => ['ROLE_VAULT_USER'],
            'create_roles' => ['ROLE_VAULT_CREATE'],
            'list_roles'   => ['ROLE_VAULT_USER'],
            'delete_roles' => ['ROLE_VAULT_DELETE'],
        ]]), false, $this->createMock(VaultSettingsRepositoryInterface::class), new ArrayAdapter()));

        self::assertTrue($checker->allowsAccess());
        self::assertTrue($checker->allowsList(new VaultUserRef('1', 'token@example.com', [], $token)));
        self::assertFalse($checker->allowsCreate(new VaultUserRef('1', 'token@example.com', [], $token)));
        self::assertTrue($checker->allowsRevoke(VaultUserRef::ofId('9', 'x', ['ROLE_VAULT_DELETE'])), 'a reference by id: its own role names');
        self::assertFalse($checker->allowsAccess(VaultUserRef::ofId('9', 'x', ['ROLE_VAULT_DELETE'])));
        foreach (['allowsAccess', 'allowsCreate', 'allowsList', 'allowsRevoke'] as $method) {
            self::assertFalse($checker->{$method}(new VaultUserRef(null)), $method . ': neither a user nor an id never means the token user');
        }

        $allowAll = new AllowAllVaultAccessChecker();
        self::assertTrue($allowAll->allowsAccess() && $allowAll->allowsCreate() && $allowAll->allowsList() && $allowAll->allowsRevoke());
        self::assertSame([], (new NullVaultTeamMembershipResolver())->teamIdsOf(VaultUserRef::ofId('1')));
        self::assertNull((new NullVaultScopeAccessResolver())->scopeAccessOf(VaultUserRef::ofId('1'), 'org-1'));
    }

    #[Test]
    public function theBuiltInDirectoryReturnsTheUserAsAReference(): void
    {
        $user    = new TestUser('3', 'carol@example.com');
        $manager = $this->createMock(ObjectManager::class);
        $manager->method('find')->willReturnCallback(static fn (string $class, string $id): ?TestUser => $id === '3' ? $user : null);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);
        $locator = new VaultUserLocator($registry, TestUser::class);

        self::assertSame($user, $locator->findUser('3')?->user());
        self::assertSame('carol@example.com', $locator->findUser('3')?->identifier);
        self::assertNull($locator->findUser('4'));
    }

    #[Test]
    public function aBrowserExtensionLoginCanBeAnsweredWithAReference(): void
    {
        $user   = new TestUser('3');
        $result = VaultBrowserExtensionAuthResult::authenticated(new VaultUserRef('3', 'u', [], $user));
        self::assertTrue($result->isSuccess());
        self::assertSame($user, $result->getUser());
        self::assertSame($user, $result->getUserRef()?->user());
        self::assertInstanceOf(VaultRefUser::class, VaultBrowserExtensionAuthResult::authenticated(VaultUserRef::ofId('7'))->getUser());
        self::assertNull(VaultBrowserExtensionAuthResult::failure('x')->getUserRef());

        $this->expectException(InvalidArgumentException::class);
        VaultBrowserExtensionAuthResult::authenticated(new VaultUserRef(null));
    }
}
