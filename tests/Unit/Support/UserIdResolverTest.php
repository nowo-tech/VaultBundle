<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Support;

use Nowo\VaultBundle\Support\UserIdResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class UserIdResolverTest extends TestCase
{
    public function testIsSameUserComparesIds(): void
    {
        $left = new class {
            public function getId(): int
            {
                return 42;
            }
        };
        $right = new class {
            public function getId(): string
            {
                return '42';
            }
        };

        self::assertTrue(UserIdResolver::isSameUser($left, $right));
    }

    public function testGetIdReturnsNullWhenMissing(): void
    {
        self::assertNull(UserIdResolver::getId(new stdClass()));
    }

    public function testGetIdReturnsNullWhenUserIdIsNull(): void
    {
        $user = new class {
            public function getId(): ?int
            {
                return null;
            }
        };

        self::assertNull(UserIdResolver::getId($user));
    }

    public function testGetIdReturnsStringValue(): void
    {
        $user = new class {
            public function getId(): int
            {
                return 7;
            }
        };

        self::assertSame('7', UserIdResolver::getId($user));
    }
}
