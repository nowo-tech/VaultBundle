<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Security\ConfigurableVaultAccessChecker;
use Nowo\VaultBundle\Tests\Support\VaultRuntimeConfigFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function in_array;

final class ConfigurableVaultAccessCheckerTest extends TestCase
{
    public function testAdminBypassesAllChecks(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_VAULT_ADMIN',
        );

        $checker = new ConfigurableVaultAccessChecker($security, $this->createProvider([
            'admin_roles'  => ['ROLE_VAULT_ADMIN'],
            'access_roles' => [],
            'create_roles' => [],
            'list_roles'   => [],
            'delete_roles' => [],
        ]));

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

        $checker = new ConfigurableVaultAccessChecker($security, $this->createProvider([
            'admin_roles'  => ['ROLE_VAULT_ADMIN'],
            'access_roles' => ['ROLE_VAULT_USER'],
            'create_roles' => ['ROLE_VAULT_CREATE'],
            'list_roles'   => ['ROLE_VAULT_USER'],
            'delete_roles' => ['ROLE_VAULT_DELETE'],
        ]));

        self::assertTrue($checker->canAccess());
        self::assertTrue($checker->canCreate());
        self::assertTrue($checker->canList());
        self::assertFalse($checker->canRevoke());
    }

    public function testDeniesWhenNoMatchingRole(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        $checker = new ConfigurableVaultAccessChecker($security, $this->createProvider([
            'admin_roles'  => ['ROLE_VAULT_ADMIN'],
            'access_roles' => ['ROLE_VAULT_USER'],
            'create_roles' => ['ROLE_VAULT_CREATE'],
            'list_roles'   => ['ROLE_VAULT_LIST'],
            'delete_roles' => ['ROLE_VAULT_DELETE'],
        ]));

        self::assertFalse($checker->canAccess());
        self::assertFalse($checker->canCreate());
        self::assertFalse($checker->canList());
        self::assertFalse($checker->canRevoke());
    }

    /**
     * @param array<string, list<string>> $security
     */
    private function createProvider(array $security): VaultRuntimeConfigProvider
    {
        return new VaultRuntimeConfigProvider(
            VaultRuntimeConfigFactory::baseline(['security' => $security]),
            false,
            $this->createMock(VaultSettingsRepositoryInterface::class),
            new ArrayAdapter(),
        );
    }
}
