<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultExtensionTokenRevokedEvent;
use Nowo\VaultBundle\Service\VaultExtensionTokenCommands;
use Nowo\VaultBundle\Service\VaultItemCreateGate;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use PHPUnit\Framework\TestCase;
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
