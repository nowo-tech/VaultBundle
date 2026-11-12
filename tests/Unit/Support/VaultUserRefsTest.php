<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Support;

use LogicException;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
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
    public function aReferenceByIdIsNeverAUserByItself(): void
    {
        self::assertNull(VaultUserRefs::toUser(VaultUserRef::ofId('abc', 'bob@example.com', ['ROLE_ADMIN'])), 'NJ-1: no stand-in for a caller reference');
        self::assertNull(VaultUserRefs::toUser(new VaultUserRef(null)));
        self::assertNull(VaultUserRefs::toUser(new VaultUserRef('x', '', [], new stdClass())), 'a user object that is no Symfony user');
    }

    #[Test]
    public function aDirectoryAnswerBecomesTheVaultStandIn(): void
    {
        $ref  = VaultUserRef::ofId('abc', 'bob@example.com', ['ROLE_USER']);
        $user = $this->standIn($ref);

        self::assertInstanceOf(VaultRefUser::class, $user);
        self::assertSame('abc', $user->getId());
        self::assertSame('bob@example.com', $user->getUserIdentifier());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        $user->eraseCredentials();
        $back = VaultUserRefs::fromUser($user);
        self::assertSame('abc', $back->id);
        self::assertSame($user, $back->user(), 'the stand-in is the user object of its reference');
        self::assertSame($user, VaultUserRefs::toUser($back));
        self::assertSame('abc', $this->standIn(VaultUserRef::ofId('abc'))?->getUserIdentifier(), 'no identifier: the id');

        $this->expectException(LogicException::class);
        VaultRefUser::fromDirectory(VaultUserRef::ofId('abc'));
    }

    #[Test]
    public function theStandInIsAVaultUserUnderTheMappingRules(): void
    {
        $relation = new VaultUserClassPolicy(TestUser::class);
        $id       = new VaultUserClassPolicy(TestUser::class, [], VaultUserMapping::ID);
        $standIn  = $this->standIn(VaultUserRef::ofId('5'));
        self::assertNotNull($standIn);

        self::assertTrue($relation->isAccepted($standIn));
        self::assertSame('5', $relation->idOf($standIn));
        self::assertSame('5', $id->idOf($standIn));
        $long = $this->standIn(VaultUserRef::ofId(str_repeat('a', 129)));
        self::assertNotNull($long);
        self::assertNull($id->idOf($long), 'the strict id-mapping rules apply to a reference too');
        self::assertNotNull($relation->idOf($long), 'the relation mapping keeps its own rules');
        $zeroWidth = $this->standIn(VaultUserRef::ofId("Us\u{200B}erA"));
        self::assertNotNull($zeroWidth);
        self::assertNull($id->idOf($zeroWidth));
    }

    /**
     * The stand-in the user-locator gate builds for a directory answer.
     */
    private function standIn(VaultUserRef $answer): ?UserInterface
    {
        $directory = new class($answer) implements VaultUserDirectoryInterface {
            public function __construct(private readonly VaultUserRef $answer)
            {
            }

            public function findUser(string $id): VaultUserRef
            {
                return $this->answer;
            }
        };

        return (new VaultUserLocatorGate(VaultPortMode::POLICY, null, $directory))->find((string) $answer->id);
    }
}
