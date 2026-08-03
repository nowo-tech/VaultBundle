<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Security;

use Nowo\VaultBundle\Security\AllowAllVaultAccessChecker;
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

        self::assertTrue($checker->canAccess());
        self::assertTrue($checker->canCreate());
        self::assertTrue($checker->canList());
        self::assertTrue($checker->canRevoke());
        self::assertTrue($checker->canAccess($user));
        self::assertTrue($checker->canCreate($user));
        self::assertTrue($checker->canList($user));
        self::assertTrue($checker->canRevoke($user));
    }
}
