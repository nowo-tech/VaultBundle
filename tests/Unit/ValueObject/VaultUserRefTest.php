<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\ValueObject;

use InvalidArgumentException;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultReauthContext;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * 1.19.0 framework-free VOs: the user object, the path and the session binding are never part of a dump, a cast, JSON
 * or a serialized copy (internal WeakMap), and a clone or an unserialized copy carries none of them.
 */
final class VaultUserRefTest extends TestCase
{
    #[Test]
    public function theUserObjectIsHiddenFromEveryDump(): void
    {
        $user = new TestUser('42', 'alice@example.com', ['ROLE_USER', 'ROLE_ADMIN']);
        $ref  = new VaultUserRef('42', 'alice@example.com', ['ROLE_USER', 7, 'ROLE_ADMIN'], $user); // @phpstan-ignore argument.type

        self::assertSame($user, $ref->user());
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $ref->roles, 'non-string roles are dropped');
        self::assertTrue($ref->hasRole('ROLE_ADMIN'));
        self::assertFalse($ref->hasRole('ROLE_VAULT_ADMIN'));
        self::assertTrue($ref->hasId());
        foreach ([var_export($ref, true), print_r($ref, true), json_encode($ref, JSON_THROW_ON_ERROR), serialize($ref), print_r((array) $ref, true)] as $dump) {
            self::assertStringNotContainsString('TestUser', $dump);
        }

        $copy = unserialize(serialize($ref));
        self::assertInstanceOf(VaultUserRef::class, $copy);
        self::assertNull($copy->user());
        self::assertSame('42', $copy->id);
        self::assertNull((clone $ref)->user());
    }

    #[Test]
    public function anIdIsKeptAsGivenAndABlankOneIsRefused(): void
    {
        $ref = VaultUserRef::ofId(' UserA', 'a', ['ROLE_USER']);
        self::assertSame(' UserA', $ref->id, 'never trimmed or normalised');
        self::assertNull($ref->user());
        self::assertTrue($ref->isSameUserAs(VaultUserRef::ofId(' UserA')));
        self::assertFalse($ref->isSameUserAs(VaultUserRef::ofId('usera')));
        self::assertFalse((new VaultUserRef(null))->isSameUserAs(new VaultUserRef(null)), 'no id: never the same user');
        self::assertFalse((new VaultUserRef(null))->hasId());

        $this->expectException(InvalidArgumentException::class);
        VaultUserRef::ofId('  ');
    }

    #[Test]
    public function theReauthContextHidesThePathAndTheSessionBinding(): void
    {
        $context = new VaultReauthContext('nowo_vault.personal_export', VaultUserRef::ofId('1'), 'POST', 'nowo_vault_personal_export', '/tools/vault/export', 'binding-REDACTED-TEST');

        self::assertSame('/tools/vault/export', $context->path());
        self::assertSame('binding-REDACTED-TEST', $context->sessionBinding());
        self::assertSame('POST', $context->method);
        self::assertSame('nowo_vault_personal_export', $context->routeName);
        foreach ([var_export($context, true), print_r($context, true), json_encode($context, JSON_THROW_ON_ERROR), serialize($context)] as $dump) {
            self::assertStringNotContainsString('binding-REDACTED-TEST', $dump);
            self::assertStringNotContainsString('/tools/vault/export', $dump);
        }
        $clone = clone $context;
        self::assertSame('', $clone->path());
        self::assertSame('', $clone->sessionBinding());
    }
}
