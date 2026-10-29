<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultExtensionTokenRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

use function array_map;
use function sort;

/**
 * Doctrine item repository (paginateByCreator and every other query) and the token repository clock (REQ-PERF-001,
 * REQ-DI-001) against the SQLite test kernel.
 */
final class VaultItemRepositoryPaginationIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DoctrineOrmVaultItemRepository $repository;
    private User $alice;
    private User $bob;
    private VaultFolder $work;
    private VaultTag $red;
    private VaultTag $blue;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $metadata            = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool                = new SchemaTool($entityManager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $this->alice = (new User())->setEmail('alice@example.com')->setPassword('x');
        $this->bob   = (new User())->setEmail('bob@example.com')->setPassword('x');
        $entityManager->persist($this->alice);
        $entityManager->persist($this->bob);
        $this->work = new VaultFolder('work', $this->alice);
        $this->red  = new VaultTag('red', $this->alice);
        $this->blue = new VaultTag('blue', $this->alice);
        $entityManager->persist($this->work);
        $entityManager->persist($this->red);
        $entityManager->persist($this->blue);
        $entityManager->flush();

        $repository = self::getContainer()->get(VaultItemRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmVaultItemRepository::class, $repository);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testPaginateByCreatorFiltersPagesAndHydratesEveryTag(): void
    {
        // a..e in "work" (a, b, c red+blue; d blue), f..g at root, h trashed; bob owns one item.
        $items = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as $title) {
            $folder = $title <= 'e' ? $this->work : null;
            $item   = new VaultItem(VaultItemType::Login, $title, $this->alice, 'ciphertext', $folder);
            $item->setTags(match (true) {
                $title <= 'c'  => [$this->red, $this->blue],
                $title === 'd' => [$this->blue],
                default        => [],
            });
            $items[$title] = $item;
            $this->repository->save($item);
        }
        $items['h']->markDeleted();
        $this->repository->save($items['h']);
        $this->repository->save(new VaultItem(VaultItemType::Login, 'bob', $this->bob, 'ciphertext'));
        $this->entityManager->clear();
        $alice = $this->reloadAlice();

        $all = $this->repository->paginateByCreator($alice, null, null, false, 0, 50);
        self::assertSame(7, $all['total']);
        self::assertCount(7, $all['items']);

        // Pages are disjoint and cover every active item exactly once (stable order on ties).
        $seen = [];
        foreach ([0, 3, 6] as $offset) {
            $page = $this->repository->paginateByCreator($alice, null, null, false, $offset, 3);
            self::assertSame(7, $page['total']);
            foreach ($page['items'] as $item) {
                $seen[] = $item->getTitle();
            }
        }
        sort($seen);
        self::assertSame(['a', 'b', 'c', 'd', 'e', 'f', 'g'], $seen);

        $folder = $this->repository->paginateByCreator($alice, $this->work->getId(), null, false, 0, 2);
        self::assertSame(5, $folder['total']);
        self::assertCount(2, $folder['items']);

        $red = $this->repository->paginateByCreator($alice, null, $this->red->getId(), false, 0, 10);
        self::assertSame(['a', 'b', 'c'], $this->sortedTitles($red['items']));
        foreach ($red['items'] as $item) {
            $tags = $item->getTags();
            self::assertInstanceOf(PersistentCollection::class, $tags);
            self::assertTrue($tags->isInitialized());
            // The tag filter uses its own alias: both tags of each item are hydrated, not only "red".
            self::assertCount(2, $tags, $item->getTitle());
        }

        $blueInWork = $this->repository->paginateByCreator($alice, $this->work->getId(), $this->blue->getId(), false, 0, 10);
        self::assertSame(4, $blueInWork['total']);
        $blueAtRoot = $this->repository->paginateByCreator($alice, '', '', false, -5, 0);
        self::assertSame(7, $blueAtRoot['total'], 'empty filters are no filters; offset/limit clamp to 0/1');
        self::assertCount(1, $blueAtRoot['items']);

        $trash = $this->repository->paginateByCreator($alice, $this->work->getId(), $this->red->getId(), true, 0, 10);
        self::assertSame(1, $trash['total']);
        self::assertSame('h', $trash['items'][0]->getTitle());
    }

    public function testEveryOtherItemQuery(): void
    {
        $login = new VaultItem(VaultItemType::Login, 'Mail login', $this->alice, 'ciphertext', $this->work);
        $login->setTags([$this->red]);
        $note    = new VaultItem(VaultItemType::SecureNote, 'Note', $this->alice, 'ciphertext');
        $trashed = new VaultItem(VaultItemType::Login, 'Old login', $this->alice, 'ciphertext', $this->work);
        $bobs    = new VaultItem(VaultItemType::Login, 'Bob login', $this->bob, 'ciphertext');
        foreach ([$login, $note, $trashed, $bobs] as $item) {
            $this->repository->save($item);
        }
        $trashed->markDeleted();
        $this->repository->save($trashed);

        self::assertSame($login, $this->repository->findById($login->getId()));
        self::assertNull($this->repository->findById('00000000-0000-0000-0000-000000000000'));
        self::assertSame(['Mail login', 'Note'], $this->sortedTitles($this->repository->findByCreator($this->alice)));
        self::assertCount(3, $this->repository->findByCreator($this->alice, true));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findByCreatorAndItemType($this->alice, VaultItemType::Login)));
        self::assertCount(2, $this->repository->findByCreatorAndItemType($this->alice, VaultItemType::Login, true));
        self::assertSame(['Note'], $this->sortedTitles($this->repository->findByCreatorAndFolder($this->alice, null)));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findByCreatorAndFolder($this->alice, $this->work->getId())));
        self::assertCount(2, $this->repository->findByCreatorAndFolder($this->alice, $this->work->getId(), true));
        self::assertSame(['Old login'], $this->sortedTitles($this->repository->findDeletedByCreator($this->alice)));
        self::assertSame(2, $this->repository->countByCreator($this->alice));
        self::assertSame(3, $this->repository->countByCreator($this->alice, true));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->searchByTitle($this->alice, 'MAIL')));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findByCreatorAndTag($this->alice, $this->red->getId())));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findByCreatorAndTag($this->alice, $this->red->getId(), $this->work->getId())));
        self::assertSame([], $this->repository->findByCreatorAndTag($this->alice, $this->red->getId(), 'other-folder'));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->searchByTitleOrTag($this->alice, 'red')));
        self::assertSame(['Mail login', 'Note'], $this->sortedTitles($this->repository->searchByTitleOrTag($this->alice, 'o')));

        $ids = [$login->getId(), $bobs->getId(), $trashed->getId()];
        self::assertSame([], $this->repository->findByIdsForViewer([], $this->bob));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findByIdsForViewer($ids, $this->bob)));
        self::assertSame([], $this->repository->findByIdsForViewerAndItemType([], $this->bob, VaultItemType::Login));
        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findByIdsForViewerAndItemType($ids, $this->bob, VaultItemType::Login)));
        self::assertSame([], $this->repository->findByIdsForViewerAndItemType($ids, $this->bob, VaultItemType::SecureNote));

        self::assertSame(['Mail login'], $this->sortedTitles($this->repository->findActiveByFolder($this->work->getId())));
        self::assertSame(4, $this->repository->countAll());
        self::assertSame(3, $this->repository->countAll(false));
        self::assertCount(2, $this->repository->findBatch(0, 2));
        self::assertCount(3, $this->repository->findBatch(-1, 10, false));

        $note->setTitle('Renamed note');
        $this->repository->saveBatch([$note]);
        $this->repository->saveBatch([]);
        $this->entityManager->clear();
        self::assertSame('Renamed note', $this->repository->findById($note->getId())?->getTitle());

        $folder = $this->entityManager->find(VaultFolder::class, $this->work->getId());
        self::assertInstanceOf(VaultFolder::class, $folder);
        self::assertSame(1, $this->repository->detachItemsFromFolder($folder));
        self::assertSame(0, $this->repository->detachItemsFromFolder($folder));
        self::assertNull($this->repository->findById($login->getId())?->getFolder());

        $reloaded = $this->repository->findById($bobs->getId());
        self::assertInstanceOf(VaultItem::class, $reloaded);
        $this->repository->remove($reloaded);
        self::assertNull($this->repository->findById($bobs->getId()));
    }

    public function testExtensionTokenRepositoryUsesInjectedClock(): void
    {
        $clock      = new MockClock('2035-06-01 12:00:00');
        $repository = new DoctrineOrmVaultExtensionTokenRepository($this->entityManager, clock: $clock);

        $valid   = new VaultExtensionToken('valid-hash', new DateTimeImmutable('2035-06-01 13:00:00'), $this->alice);
        $expired = new VaultExtensionToken('expired-hash', new DateTimeImmutable('2035-06-01 11:00:00'), $this->alice);
        $other   = new VaultExtensionToken('bob-hash', new DateTimeImmutable('2035-06-02 00:00:00'), $this->bob);
        foreach ([$valid, $expired, $other] as $token) {
            $repository->save($token);
        }

        self::assertSame($valid, $repository->findValidByTokenHash('valid-hash'));
        self::assertNull($repository->findValidByTokenHash('expired-hash'));
        self::assertCount(2, $repository->findByUser($this->alice));
        self::assertSame([], $repository->findByUser(new User()), 'user without id');

        // The mocked clock, not the system time (year 2026), decides expiry.
        $clock->modify('2035-06-01 14:00:00');
        self::assertNull($repository->findValidByTokenHash('valid-hash'));
        self::assertSame(2, $repository->removeExpired());
        $this->entityManager->clear();
        self::assertSame(['bob-hash'], array_map(
            static fn (VaultExtensionToken $token): string => $token->getTokenHash(),
            $this->entityManager->getRepository(VaultExtensionToken::class)->findAll(),
        ));

        // Without a clock the system time applies (2035 tokens are still valid today).
        $systemClock = new DoctrineOrmVaultExtensionTokenRepository($this->entityManager);
        self::assertNotNull($systemClock->findValidByTokenHash('bob-hash'));

        $bobToken = $systemClock->findValidByTokenHash('bob-hash');
        self::assertInstanceOf(VaultExtensionToken::class, $bobToken);
        $systemClock->remove($bobToken);
        self::assertNull($systemClock->findValidByTokenHash('bob-hash'));
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    private function reloadAlice(): User
    {
        $alice = $this->entityManager->find(User::class, $this->alice->getId());
        self::assertInstanceOf(User::class, $alice);

        return $alice;
    }

    /**
     * @param list<VaultItem> $items
     *
     * @return list<string>
     */
    private function sortedTitles(array $items): array
    {
        $titles = array_map(static fn (VaultItem $item): string => $item->getTitle(), $items);
        sort($titles);

        return $titles;
    }
}
