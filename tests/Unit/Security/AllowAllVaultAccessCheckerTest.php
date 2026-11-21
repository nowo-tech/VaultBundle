<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
use Nowo\VaultBundle\Support\VaultUserRefs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

#[CoversClass(AllowAllVaultAccessChecker::class)]
final class AllowAllVaultAccessCheckerTest extends TestCase
{
    public function testAllowsNullAndAuthenticatedUsers(): void
    {
        $checker = new AllowAllVaultAccessChecker();
        $user    = new InMemoryUser('demo', null, ['ROLE_USER']);

        self::assertTrue($checker->allowsAccess());
        self::assertTrue($checker->allowsCreate());
        self::assertTrue($checker->allowsList());
        self::assertTrue($checker->allowsRevoke());
        self::assertTrue($checker->allowsAccess(VaultUserRefs::fromUser($user)));
        self::assertTrue($checker->allowsCreate(VaultUserRefs::fromUser($user)));
        self::assertTrue($checker->allowsList(VaultUserRefs::fromUser($user)));
        self::assertTrue($checker->allowsRevoke(VaultUserRefs::fromUser($user)));
    }
}
