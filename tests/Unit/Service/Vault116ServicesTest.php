<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use DomainException;
use InvalidArgumentException;
use LogicException;
use Nowo\VaultBundle\Command\CheckDowngradeCommand;
use Nowo\VaultBundle\Dto\VaultCatalogueQuery;
use Nowo\VaultBundle\Dto\VaultShareFormData;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultScopeItemRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Security\NullVaultTeamMembershipResolver;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantExpiryService;
use Nowo\VaultBundle\Service\VaultGrantPurgeResult;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultItemUpdater;
use Nowo\VaultBundle\Service\VaultScopeItemLister;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Tests\Stub\InMemoryGrantRepository;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Branches of the 1.16.0 services that the kernel tests do not reach (missing collaborators, fallbacks).
 */
final class Vault116ServicesTest extends TestCase
{
    public function testScopeListingsNeedTheDoctrineRepositories(): void
    {
        $guard = new VaultAccessGuard(new InMemoryGrantRepository(), new NullVaultTeamMembershipResolver(), new EventDispatcher());

        foreach ([
            fn (): array => (new VaultFolderService($this->createStub(VaultFolderRepositoryInterface::class)))->listForScope('org-1'),
            fn (): array => (new VaultTagService($this->createStub(VaultTagRepositoryInterface::class), $this->createStub(VaultItemRepositoryInterface::class)))->listForScope('org-1'),
            fn (): array => (new VaultScopeItemLister($this->createStub(VaultItemRepositoryInterface::class), $guard))->list(new TestUser('1'), 'org-1'),
            fn (): VaultGrantPurgeResult => (new VaultGrantExpiryService($this->createStub(VaultGrantRepositoryInterface::class), new VaultGrantService(new InMemoryGrantRepository())))->purgeExpired(),
        ] as $call) {
            try {
                $call();
                self::fail('LogicException expected');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testScopeListingRefusesNonMembers(): void
    {
        $items = $this->createStubForIntersectionOfInterfaces([VaultItemRepositoryInterface::class, VaultScopeItemRepositoryInterface::class]);
        $guard = new VaultAccessGuard(new InMemoryGrantRepository(), new NullVaultTeamMembershipResolver(), new EventDispatcher());

        $this->expectException(VaultAccessDeniedException::class);
        (new VaultScopeItemLister($items, $guard))->list(new TestUser('1'), 'org-1');
    }

    public function testCatalogueNeedsAnOrmManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $resolver = new class implements VaultScopeAccessResolverInterface {
            public function resolve(UserInterface $user, string $scope): VaultScopeAccess
            {
                return new VaultScopeAccess(null, true);
            }
        };
        $guard = new VaultAccessGuard(new InMemoryGrantRepository(), new NullVaultTeamMembershipResolver(), new EventDispatcher(), new RequestScopedScopeAccessCache($resolver));

        $this->expectException(LogicException::class);
        (new VaultCatalogue($registry, $guard, new EventDispatcher()))->page(new TestUser('1'), 'org-1', new VaultCatalogueQuery());
    }

    public function testScopeCreationWithoutGuardIsDenied(): void
    {
        $this->expectException(VaultAccessDeniedException::class);
        (new VaultItemCreator($this->createStub(VaultItemRepositoryInterface::class), $this->createStub(VaultPayloadCryptographerInterface::class)))
            ->createInScope(new TestUser('1'), VaultItemType::Login, 'x', 'org-1', []);
    }

    public function testScopeFolderCreationWithoutGuardIsDenied(): void
    {
        $this->expectException(VaultAccessDeniedException::class);
        (new VaultFolderService($this->createStub(VaultFolderRepositoryInterface::class)))->createInScope(new TestUser('1'), 'Ops', 'org-1');
    }

    public function testEmptyScopeTagNameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new VaultTagService($this->createStub(VaultTagRepositoryInterface::class), $this->createStub(VaultItemRepositoryInterface::class)))->findOrCreateInScope('org-1', '  ');
    }

    public function testSharedItemsDropScopeItemsWithoutTheGuard(): void
    {
        $owner    = new TestUser('1');
        $personal = new VaultItem(VaultItemType::Login, 'Mine', $owner, 'c');
        $scope    = VaultItem::forScope(VaultItemType::Login, 'Ops', 'org-1', null, 'c');
        $grants   = $this->createStub(VaultGrantRepositoryInterface::class);
        $grants->method('findGrantedResourceIds')->willReturn([$personal->getId(), $scope->getId()]);
        $items = $this->createStub(VaultItemRepositoryInterface::class);
        $items->method('findByIdsForViewer')->willReturn([$personal, $scope]);
        $items->method('findByIdsForViewerAndItemType')->willReturn([$scope]);

        $resolver = new VaultSharedItemResolver($grants, $items, new NullVaultTeamMembershipResolver());
        self::assertSame([$personal], $resolver->resolve(new TestUser('2')));
        self::assertSame([], $resolver->resolveByItemType(new TestUser('2'), VaultItemType::Login));
    }

    public function testUpdaterTreatsAnUndecryptableStoredPayloadAsASecretChange(): void
    {
        $crypto = $this->createStub(VaultPayloadCryptographerInterface::class);
        $crypto->method('decrypt')->willThrowException(new RuntimeException('Vault decryption failed.'));
        $crypto->method('encrypt')->willReturn('c2');
        $clock      = new MockClock('2027-01-01 00:00:00');
        $dispatcher = new EventDispatcher();
        $kinds      = [];
        $dispatcher->addListener(VaultEvents::ITEM_CHANGED, static function (VaultItemChangeEvent $event) use (&$kinds): void {
            $kinds[] = $event->getKind() . ($event->isSecretChanged() ? '+secret' : '') . '@' . $event->getOccurredAt()->format('Y') . '#' . $event->getActorId() . '/' . $event->getOwnerKey() . '/' . ($event->getItemId() !== '' ? 'id' : '');
        });
        $owner  = new TestUser('1');
        $item   = new VaultItem(VaultItemType::Passport, 'P', $owner, 'c');
        $folder = new VaultFolder('Docs', $owner);

        (new VaultItemUpdater($this->createStub(VaultItemRepositoryInterface::class), $crypto, $dispatcher, $clock))->update($item, 'P', [], $folder, $owner);

        self::assertEquals($clock->now(), $item->getSecretChangedAt());
        self::assertSame(['updated+secret@2027#1/user:1/id', 'moved@2027#1/user:1/id'], $kinds);
        self::assertContains('attachmentContent', VaultItemUpdater::secretKeys(VaultItemType::Passport));
        self::assertNotContains('attachmentContent', VaultItemUpdater::secretKeys(VaultItemType::Login));
    }

    public function testShareFormExpiryChoices(): void
    {
        $now  = new DateTimeImmutable('2026-11-05 12:00:00');
        $data = new VaultShareFormData();
        self::assertNull($data->resolveExpiresAt($now));
        $data->expiresIn = '';
        self::assertNull($data->resolveExpiresAt($now));
        $data->expiresIn = 'PT1H';
        self::assertEquals($now->modify('+1 hour'), $data->resolveExpiresAt($now));
        $data->expiresIn = 'custom';
        self::assertNull($data->resolveExpiresAt($now));
        $data->expiresAt = $now->modify('+3 days');
        self::assertSame($data->expiresAt, $data->resolveExpiresAt($now));
    }

    public function testUserLocator(): void
    {
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('find')->willReturnCallback(static function (string $class, string $id): object {
            if ($id === 'boom') {
                throw new RuntimeException('Invalid id type.');
            }

            return $id === '1' ? new TestUser('1') : new stdClass();
        });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);
        $locator = new VaultUserLocator($registry, TestUser::class);

        self::assertInstanceOf(UserInterface::class, $locator->find('1'));
        self::assertNull($locator->find('2'), 'Not a UserInterface.');
        self::assertNull($locator->find('boom'));
        self::assertNull($locator->find(''));
    }

    public function testCheckDowngradeWithoutAnOrmManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $tester = new CommandTester(new CheckDowngradeCommand($registry));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('no ORM entity manager', $tester->getDisplay());
    }

    public function testExpiryDryRunHonoursTheLimit(): void
    {
        $clock  = new MockClock('2026-11-05 12:00:00');
        $grants = new InMemoryGrantRepository($clock->now());
        foreach (['a', 'b', 'c'] as $grantee) {
            $grants->save(new VaultGrant(VaultResourceType::Item, 'i', GranteeType::User, $grantee, VaultPermission::Read, null, $clock->now()->modify('-1 minute')));
        }
        $service = new VaultGrantExpiryService($grants, new VaultGrantService($grants), $clock);

        self::assertSame(2, $service->purgeExpired(limit: 2, dryRun: true)->expired);
        self::assertSame(2, $service->purgeExpired(batchSize: 1, limit: 2)->expired);
        self::assertSame(1, $service->purgeExpired()->expired);
    }

    public function testScopeOwnerExceptionIsADomainError(): void
    {
        self::assertInstanceOf(DomainException::class, new VaultOwnerException('x'));
        self::assertSame('Vault access denied.', (new VaultAccessDeniedException())->getMessage());
    }
}
