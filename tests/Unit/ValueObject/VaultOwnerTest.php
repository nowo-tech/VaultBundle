<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\ValueObject;

use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function str_repeat;

final class VaultOwnerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function scopeKeys(): iterable
    {
        yield 'project uuid' => ['project-0b9e4c1a-6f2d-4d1e-9a51-6c0f2c8d1e77', true];
        yield 'org' => ['org-1', true];
        yield 'single char' => ['a', true];
        yield 'underscore' => ['team_a', true];
        yield '128 chars' => [str_repeat('a', 128), true];
        yield '129 chars' => [str_repeat('a', 129), false];
        yield 'uppercase' => ['Project-1', false];
        yield 'dot dot slash' => ['../etc', false];
        yield 'leading dash' => ['-project', false];
        yield 'empty' => ['', false];
        yield 'space' => ['org 1', false];
        yield 'trailing newline' => ["org-1\n", false];
    }

    #[DataProvider('scopeKeys')]
    public function testScopePattern(string $key, bool $valid): void
    {
        self::assertSame($valid, VaultOwner::isValidScope($key));
        if (!$valid) {
            $this->expectException(VaultOwnerException::class);
        }

        self::assertSame('scope:' . $key, VaultOwner::scope($key)->key());
    }

    public function testUserOwner(): void
    {
        $alice = new TestUser('7', 'alice@example.com');
        $owner = VaultOwner::user($alice);

        self::assertFalse($owner->isScope());
        self::assertSame($alice, $owner->getUser());
        self::assertNull($owner->getScope());
        self::assertSame('user:7', $owner->key());
        self::assertTrue($owner->isUser(new TestUser('7', 'other@example.com')));
        self::assertFalse($owner->isUser(new TestUser('8')));
        self::assertSame('user:', VaultOwner::user(new stdClass())->key());
    }

    public function testScopeOwner(): void
    {
        $owner = VaultOwner::scope('org-1');

        self::assertTrue($owner->isScope());
        self::assertNull($owner->getUser());
        self::assertSame('org-1', $owner->getScope());
        self::assertFalse($owner->isUser(new TestUser('7')));
    }

    public function testEquals(): void
    {
        self::assertTrue(VaultOwner::user(new TestUser('1'))->equals(VaultOwner::user(new TestUser('1', 'x@example.com'))));
        self::assertFalse(VaultOwner::user(new TestUser('1'))->equals(VaultOwner::user(new TestUser('2'))));
        self::assertTrue(VaultOwner::scope('org-1')->equals(VaultOwner::scope('org-1')));
        self::assertFalse(VaultOwner::scope('org-1')->equals(VaultOwner::scope('org-2')));
        self::assertFalse(VaultOwner::scope('org-1')->equals(VaultOwner::user(new TestUser('1'))));
        self::assertFalse(VaultOwner::user(new TestUser('1'))->equals(VaultOwner::scope('org-1')));
    }

    public function testFromColumns(): void
    {
        self::assertSame('user:3', VaultOwner::fromColumns(new TestUser('3'), null)->key());
        self::assertSame('scope:org-1', VaultOwner::fromColumns(null, 'org-1')->key());
    }

    public function testFromColumnsRefusesBothOrNeither(): void
    {
        $this->expectException(VaultOwnerException::class);
        VaultOwner::fromColumns(new TestUser('3'), 'org-1');
    }

    public function testFromColumnsRefusesNeither(): void
    {
        $this->expectException(VaultOwnerException::class);
        VaultOwner::fromColumns(null, null);
    }
}
