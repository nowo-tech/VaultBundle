<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use PHPUnit\Framework\TestCase;
use stdClass;

final class VaultUserIdTest extends TestCase
{
    public function testExposesTheIdLikeAHostUser(): void
    {
        $id = new VaultUserId('u-1');

        self::assertSame('u-1', $id->getId());
        self::assertSame('u-1', (string) $id);
    }

    public function testRefusesAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new VaultUserId(' ');
    }

    public function testWrapAndIdOfStoredColumns(): void
    {
        $user = new TestUser('u-2');

        self::assertNull(VaultUserId::wrap(null));
        self::assertSame($user, VaultUserId::wrap($user));
        self::assertEquals(new VaultUserId('u-3'), VaultUserId::wrap('u-3'));

        self::assertNull(VaultUserId::idOf(null));
        self::assertSame('u-3', VaultUserId::idOf('u-3'));
        self::assertSame('u-2', VaultUserId::idOf($user));
        self::assertNull(VaultUserId::idOf(new stdClass()));
    }
}
