<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

use function in_array;

final class ConfigurableVaultAccessCheckerTest extends TestCase
{
    public function testAdminBypassesAllChecks(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_VAULT_ADMIN',
        );

        $checker = new ConfigurableVaultAccessChecker(
            $security,
            adminRoles: ['ROLE_VAULT_ADMIN'],
            accessRoles: [],
            createRoles: [],
            listRoles: [],
            deleteRoles: [],
        );

        self::assertTrue($checker->canAccess());
        self::assertTrue($checker->canCreate());
        self::assertTrue($checker->canList());
        self::assertTrue($checker->canRevoke());
    }

    public function testRoleBasedPermissions(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => in_array($role, ['ROLE_VAULT_USER', 'ROLE_VAULT_CREATE'], true),
        );

        $checker = new ConfigurableVaultAccessChecker(
            $security,
            adminRoles: ['ROLE_VAULT_ADMIN'],
            accessRoles: ['ROLE_VAULT_USER'],
            createRoles: ['ROLE_VAULT_CREATE'],
            listRoles: ['ROLE_VAULT_USER'],
            deleteRoles: ['ROLE_VAULT_DELETE'],
        );

        self::assertTrue($checker->canAccess());
        self::assertTrue($checker->canCreate());
        self::assertTrue($checker->canList());
        self::assertFalse($checker->canRevoke());
    }

    public function testDeniesWhenNoMatchingRole(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        $checker = new ConfigurableVaultAccessChecker(
            $security,
            adminRoles: ['ROLE_VAULT_ADMIN'],
            accessRoles: ['ROLE_VAULT_USER'],
            createRoles: ['ROLE_VAULT_CREATE'],
            listRoles: ['ROLE_VAULT_LIST'],
            deleteRoles: ['ROLE_VAULT_DELETE'],
        );

        self::assertFalse($checker->canAccess());
        self::assertFalse($checker->canCreate());
        self::assertFalse($checker->canList());
        self::assertFalse($checker->canRevoke());
    }
}
