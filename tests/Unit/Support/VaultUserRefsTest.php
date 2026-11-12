<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Support;

use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_repeat;

/**
 * 1.19.0 conversions between the vault's users and the framework-free references.
 */
final class VaultUserRefsTest extends TestCase
{
    #[Test]
    public function aUserBecomesAReferenceWithItsObject(): void
    {
        $user = new TestUser('7', 'alice@example.com', ['ROLE_USER']);
        $ref  = VaultUserRefs::fromUser($user);

        self::assertSame('7', $ref->id);
        self::assertSame('alice@example.com', $ref->identifier);
        self::assertSame(['ROLE_USER'], $ref->roles);
        self::assertSame($user, $ref->user());
        self::assertSame($user, VaultUserRefs::toUser($ref), 'the same object comes back');
        self::assertNull(VaultUserRefs::fromNullableUser(null));
    }

    #[Test]
    public function aBlankOrMissingIdBecomesNull(): void
    {
        self::assertNull(VaultUserRefs::fromUser(new TestUser('   '))->id);
        $noId = new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'x';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }
        };
        self::assertNull(VaultUserRefs::fromUser($noId)->id);
    }

    #[Test]
    public function aReferenceByIdBecomesTheVaultStandIn(): void
    {
        $ref  = VaultUserRef::ofId('abc', 'bob@example.com', ['ROLE_USER']);
        $user = VaultUserRefs::toUser($ref);

        self::assertInstanceOf(VaultRefUser::class, $user);
        self::assertSame('abc', $user->getId());
        self::assertSame('bob@example.com', $user->getUserIdentifier());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        $user->eraseCredentials();
        self::assertSame($ref, VaultUserRefs::fromUser($user), 'the stand-in gives back its reference');
        self::assertSame('abc', VaultRefUser::of(VaultUserRef::ofId('abc'))?->getUserIdentifier(), 'no identifier: the id');
        self::assertNull(VaultUserRefs::toUser(new VaultUserRef(null)), 'neither an id nor a user object');
        self::assertInstanceOf(VaultRefUser::class, VaultUserRefs::toUser(new VaultUserRef('x', '', [], new stdClass())), 'a user object that is no Symfony user: the id');
    }

    #[Test]
    public function theStandInIsAVaultUserUnderTheMappingRules(): void
    {
        $relation = new VaultUserClassPolicy(TestUser::class);
        $id       = new VaultUserClassPolicy(TestUser::class, [], VaultUserMapping::ID);
        $standIn  = VaultUserRefs::toUser(VaultUserRef::ofId('5'));
        self::assertNotNull($standIn);

        self::assertTrue($relation->isAccepted($standIn));
        self::assertSame('5', $relation->idOf($standIn));
        self::assertSame('5', $id->idOf($standIn));
        $long = VaultUserRefs::toUser(VaultUserRef::ofId(str_repeat('a', 129)));
        self::assertNotNull($long);
        self::assertNull($id->idOf($long), 'the strict id-mapping rules apply to a reference too');
        self::assertNotNull($relation->idOf($long), 'the relation mapping keeps its own rules');
        $zeroWidth = VaultUserRefs::toUser(VaultUserRef::ofId("Us\u{200B}erA"));
        self::assertNotNull($zeroWidth);
        self::assertNull($id->idOf($zeroWidth));
    }
}
