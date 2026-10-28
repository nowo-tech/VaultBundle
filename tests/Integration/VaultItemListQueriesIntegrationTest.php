<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;

/**
 * Items page queries: tags are fetch-joined (no lazy load per item) and folder counts come from one GROUP BY.
 */
final class VaultItemListQueriesIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DoctrineOrmVaultItemRepository $repository;
    private User $user;
    /** @var array<string, VaultFolder> */
    private array $folders = [];

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

        $this->user = (new User())->setEmail('alice@example.com')->setPassword('x');
        $entityManager->persist($this->user);
        $work = new VaultTag('work', $this->user);
        $home = new VaultTag('home', $this->user);
        $entityManager->persist($work);
        $entityManager->persist($home);
        foreach (['empty', 'two', 'one'] as $name) {
            $this->folders[$name] = new VaultFolder($name, $this->user);
            $entityManager->persist($this->folders[$name]);
        }
        $items = [
            ['a', 'two', [$work, $home], false],
            ['b', 'two', [$work], false],
            ['c', 'one', [], false],
            ['d', 'one', [$home], true],
            ['e', null, [], false],
        ];
        foreach ($items as [$title, $folder, $tags, $trashed]) {
            $item = new VaultItem(VaultItemType::Login, $title, $this->user, 'ciphertext', $folder === null ? null : $this->folders[$folder]);
            $item->setTags($tags);
            if ($trashed) {
                $item->markDeleted();
            }
            $entityManager->persist($item);
        }
        $entityManager->flush();
        $entityManager->clear();

        $repository = self::getContainer()->get(VaultItemRepositoryInterface::class);
        self::assertInstanceOf(DoctrineOrmVaultItemRepository::class, $repository);
        $this->repository = $repository;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testCountActiveByFoldersCountsEveryFolderInOneQuery(): void
    {
        $ids = array_map(static fn (VaultFolder $f): string => $f->getId(), $this->folders);

        self::assertSame(
            [$ids['empty'] => 0, $ids['two'] => 2, $ids['one'] => 1],
            $this->repository->countActiveByFolders([$ids['empty'], $ids['two'], $ids['one']]),
        );
        self::assertSame([], $this->repository->countActiveByFolders([]));
        self::assertSame(2, $this->repository->countActiveByFolder($ids['two']));
    }

    public function testListQueriesReturnItemsWithTagsAlreadyLoaded(): void
    {
        $user = $this->entityManager->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);

        foreach ([
            $this->repository->findByCreator($user),
            $this->repository->findByCreatorAndItemType($user, VaultItemType::Login),
            $this->repository->findByCreatorAndFolder($user, $this->folders['two']->getId()),
        ] as $items) {
            self::assertNotSame([], $items);
            foreach ($items as $item) {
                $tags = $item->getTags();
                self::assertInstanceOf(PersistentCollection::class, $tags);
                self::assertTrue($tags->isInitialized(), $item->getTitle() . ': tags must be fetch-joined');
            }
        }

        $titles = [];
        foreach ($this->repository->findByCreatorAndFolder($user, $this->folders['two']->getId()) as $item) {
            $titles[$item->getTitle()] = $item->getTags()->count();
        }
        self::assertEqualsCanonicalizing(['a' => 2, 'b' => 1], $titles);
        self::assertCount(4, $this->repository->findByCreator($user), 'one row per item, not per tag');
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }
}
