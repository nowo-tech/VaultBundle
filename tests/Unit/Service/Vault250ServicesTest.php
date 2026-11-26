<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultExtensionTokenRevokedEvent;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultExtensionTokenCommands;
use Nowo\VaultBundle\Service\VaultItemCreateGate;
use Nowo\VaultBundle\Support\VaultUserBinding;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * 2.5.0 edge paths of the create gate, the token port and the revoke event.
 */
final class Vault250ServicesTest extends TestCase
{
    public function testWithoutADispatcherTheGateAllowsEverything(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManager');
        (new VaultItemCreateGate($registry))->check(new InMemoryUser('alice', null), VaultOwner::scope('org-1'), VaultItemType::Login);
        $this->addToAssertionCount(1);
    }

    public function testTheGateNeedsAnOrmManagerOnceAListenerCounts(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            $event->itemCount();

            return $event;
        });

        $this->expectException(LogicException::class);
        (new VaultItemCreateGate($registry, $dispatcher))->check(new InMemoryUser('alice', null), VaultOwner::scope('org-1'), VaultItemType::Login);
    }

    public function testTheTokenPortNeedsAnOrmManager(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));

        $this->expectException(LogicException::class);
        (new VaultExtensionTokenCommands($registry, new VaultUserRefResolver()))->listForUser(new VaultUserRef('7', 'alice', [], new IdUser('7')));
    }

    public function testAUserTheVaultDoesNotAcceptHasNoToken(): void
    {
        $factory = $this->createMock(ClassMetadataFactory::class);
        $factory->method('isTransient')->willReturn(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getMetadataFactory')->willReturn($factory);
        $em->expects(self::never())->method('createQuery');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        $users = $this->createMock(VaultUserReferenceResolverInterface::class);
        $users->method('reference')->willThrowException(new AccessDeniedException());

        self::assertSame([], (new VaultExtensionTokenCommands($registry, new VaultUserRefResolver(), users: $users))->listForUser(new VaultUserRef('7', 'alice', [], new IdUser('7'))));
    }

    public function testOnPostgresqlTheGateTakesTheOwnersAdvisoryLock(): void
    {
        $result = $this->createMock(Result::class);
        $result->expects(self::once())->method('free');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('executeQuery')
            ->with('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['nowo_vault.item_create:scope:org-1'])
            ->willReturn($result);
        self::assertTrue(VaultItemCreateGate::ownerLock($connection, 'scope:org-1'));

        $other = $this->createMock(Connection::class);
        $other->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $other->expects(self::never())->method('executeQuery');
        self::assertFalse(VaultItemCreateGate::ownerLock($other, 'scope:org-1'));
    }

    public function testTheUserBindingFallsBackToTheId(): void
    {
        $directory = $this->createMock(VaultUserDirectoryInterface::class);
        $directory->method('findUser')->willReturnCallback(static fn (string $id): ?VaultUserRef => $id === '7' ? VaultUserRef::ofId('7', 'alice') : null);
        $locator  = new VaultUserLocatorGate($directory);
        $refusing = $this->createMock(VaultUserReferenceResolverInterface::class);
        $refusing->method('mapsById')->willReturn(false);
        $refusing->method('reference')->willThrowException(new AccessDeniedException());
        $byId = $this->createMock(VaultUserReferenceResolverInterface::class);
        $byId->method('mapsById')->willReturn(true);

        self::assertSame('7', VaultUserBinding::of('7', $locator, $byId), 'Id mapping: the id itself.');
        self::assertSame('8', VaultUserBinding::of('8', $locator, $refusing), 'Unknown to the directory: the id.');
        self::assertSame('7', VaultUserBinding::of('7', $locator, $refusing), 'Refused by the resolver: the id.');
        self::assertInstanceOf(UserInterface::class, VaultUserBinding::of('7', $locator, null), 'No resolver: the user.');
    }

    public function testTheCatalogueOwnerFilterNeverBindsAStandIn(): void
    {
        $directory = $this->createMock(VaultUserDirectoryInterface::class);
        $directory->method('findUser')->willReturnCallback(static fn (string $id): ?VaultUserRef => VaultUserRef::ofId($id, 'alice'));
        $refusing = $this->createMock(VaultUserReferenceResolverInterface::class);
        $refusing->method('mapsById')->willReturn(false);
        $refusing->method('reference')->willThrowException(new AccessDeniedException());
        $registry   = self::createStub(ManagerRegistry::class);
        $dispatcher = self::createStub(EventDispatcherInterface::class);
        $guard      = (new ReflectionClass(VaultAccessGuard::class))->newInstanceWithoutConstructor();
        $owner      = static function (?VaultUserReferenceResolverInterface $users) use ($directory, $registry, $dispatcher, $guard): mixed {
            $catalogue = new VaultCatalogue(
                $registry,
                $guard,
                $dispatcher,
                null,
                new VaultUserLocatorGate($directory),
                $users,
            );

            return (new ReflectionMethod($catalogue, 'owner'))->invoke($catalogue, '7');
        };

        self::assertFalse($owner($refusing), 'A user the resolver refuses matches nothing.');
        self::assertInstanceOf(UserInterface::class, $owner(null), 'Without a resolver: the user as found.');
    }

    public function testTheRevokeEventCarriesIdsAndTheTime(): void
    {
        $at    = new DateTimeImmutable('2026-11-26 10:00:00');
        $event = new VaultExtensionTokenRevokedEvent('7', ['t-1'], VaultExtensionTokenRevokedEvent::REASON_ALL, $at);

        self::assertSame('7', $event->getUserId());
        self::assertSame(['t-1'], $event->getTokenIds());
        self::assertSame('revoked_all', $event->getReason());
        self::assertSame($at, $event->getOccurredAt());
    }
}

/**
 * A Symfony user with an id.
 */
final readonly class IdUser implements UserInterface
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'user-' . $this->id;
    }
}
