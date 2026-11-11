<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Tests\App\Ddd\AppSecurityUser;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\ValueObject\VaultUserId;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function str_repeat;

/**
 * 1.17.0: accepted user classes and the default user reference resolver in both user mappings.
 */
final class VaultUserReferenceTest extends TestCase
{
    public function testPolicyAcceptsUserClassVaultUserIdsAndListedClassesOnly(): void
    {
        $policy = new VaultUserClassPolicy('\\' . TestUser::class, ['\\' . AppSecurityUser::class]);

        self::assertTrue($policy->isUserClass(new TestUser()));
        self::assertFalse($policy->isUserClass(new AppSecurityUser(1, 'a@example.com')));
        self::assertTrue($policy->isAccepted(new TestUser()));
        self::assertTrue($policy->isAccepted(new VaultUserId('7')));
        self::assertTrue($policy->isAccepted(new AppSecurityUser(1, 'a@example.com')));
        self::assertFalse($policy->isAccepted(new OtherDomainUser('1', 'x@example.com')));

        self::assertSame('user-1', $policy->idOf(new TestUser()));
        self::assertSame(1, $policy->idOf(new AppSecurityUser(1, 'a@example.com')));
        self::assertNull($policy->idOf(new OtherDomainUser('1', 'x@example.com')), 'Not accepted.');
        self::assertNull($policy->idOf(new AppSecurityUser(0, 'a@example.com')), 'Unusable id.');
        self::assertNull((new VaultUserClassPolicy(stdClass::class))->idOf(new stdClass()), 'No getId().');
    }

    public function testUsableIds(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'abc';
            }
        };

        self::assertTrue(VaultUserClassPolicy::isUsableId(5));
        self::assertFalse(VaultUserClassPolicy::isUsableId(0));
        self::assertFalse(VaultUserClassPolicy::isUsableId(-3));
        self::assertFalse(VaultUserClassPolicy::isUsableId(null));
        self::assertFalse(VaultUserClassPolicy::isUsableId(1.5));
        self::assertFalse(VaultUserClassPolicy::isUsableId(' '));
        self::assertFalse(VaultUserClassPolicy::isUsableId('0'));
        self::assertFalse(VaultUserClassPolicy::isUsableId('-1'));
        self::assertTrue(VaultUserClassPolicy::isUsableId('12'));
        self::assertTrue(VaultUserClassPolicy::isUsableId('0b5f2c1e-uuid'));
        self::assertFalse(VaultUserClassPolicy::isUsableId(' UserA'), 'LQ-V-I2: never trimmed.');
        self::assertFalse(VaultUserClassPolicy::isUsableId('UserA '));
        self::assertFalse(VaultUserClassPolicy::isUsableId(str_repeat('a', 129)), 'LQ-V-I2: never truncated.');
        self::assertTrue(VaultUserClassPolicy::isUsableId(str_repeat('a', 128)));
        self::assertTrue(VaultUserClassPolicy::isUsableId($stringable));
    }

    public function testIdMappingReturnsTheStringIdOfAcceptedUsers(): void
    {
        $resolver = new DoctrineVaultUserReferenceResolver(TestUser::class, new VaultUserClassPolicy(TestUser::class, [AppSecurityUser::class]), VaultUserMapping::ID);

        self::assertTrue($resolver->mapsById());
        self::assertSame('user-1', $resolver->reference(new TestUser()));
        self::assertSame('42', $resolver->reference(new AppSecurityUser(42, 'a@example.com')));
        self::assertSame('9', $resolver->reference(new VaultUserId('9')));
        self::assertTrue($resolver->isAccepted(new TestUser()));
        self::assertFalse($resolver->isAccepted(new OtherDomainUser('1', 'x@example.com')));
    }

    public function testRelationMappingKeepsUserClassAndReferencesAcceptedUsers(): void
    {
        $alice     = new User();
        $reference = new User();
        $em        = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getReference')->with(User::class, 42)->willReturn($reference);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('vault')->willReturn($em);
        $resolver = new DoctrineVaultUserReferenceResolver('\\' . User::class, new VaultUserClassPolicy(User::class, [AppSecurityUser::class]), VaultUserMapping::RELATION, $registry, 'vault');

        self::assertFalse($resolver->mapsById());
        self::assertSame($reference, $resolver->reference(new AppSecurityUser(42, 'a@example.com')));
        self::assertFalse($resolver->isAccepted($alice), 'A user_class instance without an id has no usable id.');
    }

    public function testRelationMappingWithoutReferenceSupportReturnsTheUser(): void
    {
        $user    = new AppSecurityUser(42, 'a@example.com');
        $classes = new VaultUserClassPolicy(User::class, [AppSecurityUser::class]);

        self::assertSame($user, (new DoctrineVaultUserReferenceResolver(User::class, $classes))->reference($user), 'No registry.');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        self::assertSame($user, (new DoctrineVaultUserReferenceResolver(User::class, $classes, VaultUserMapping::RELATION, $registry))->reference($user), 'Manager without getReference().');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getReference')->willReturn(null);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        self::assertSame($user, (new DoctrineVaultUserReferenceResolver(User::class, $classes, VaultUserMapping::RELATION, $registry))->reference($user), 'No reference.');

        $own = new TestUser();
        self::assertSame($own, (new DoctrineVaultUserReferenceResolver(TestUser::class, new VaultUserClassPolicy(TestUser::class), VaultUserMapping::RELATION, $registry))->reference($own), 'user_class instance unchanged.');
    }

    public function testRefusesOtherUsers(): void
    {
        $resolver = new DoctrineVaultUserReferenceResolver(TestUser::class, new VaultUserClassPolicy(TestUser::class));

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('OtherDomainUser is not a vault user');
        $resolver->reference(new OtherDomainUser('user-1', 'x@example.com'));
    }
}
