<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultFolderRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultSettingsRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultTagRepositoryInterface;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_map;
use function sort;

/**
 * Doctrine ORM repositories for folders, grants, tags and settings against the SQLite test kernel.
 */
final class VaultRepositoriesIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private User $alice;
    private User $bob;

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
        $entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testFolderRepositoryListsActiveAndDeletedFoldersPerCreator(): void
    {
        $repository = $this->service(VaultFolderRepositoryInterface::class);
        self::assertInstanceOf(VaultFolderRepositoryInterface::class, $repository);

        $beta    = new VaultFolder('beta', $this->alice);
        $alpha   = new VaultFolder('alpha', $this->alice);
        $trashed = new VaultFolder('trashed', $this->alice);
        $foreign = new VaultFolder('foreign', $this->bob);
        foreach ([$beta, $alpha, $trashed, $foreign] as $folder) {
            $repository->save($folder);
        }
        $trashed->markDeleted();
        $repository->save($trashed);

        self::assertSame(['alpha', 'beta'], $this->names($repository->findByCreator($this->alice)));
        self::assertSame(['alpha', 'beta', 'trashed'], $this->names($repository->findByCreator($this->alice, true)));
        self::assertSame(['trashed'], $this->names($repository->findDeletedByCreator($this->alice)));
        self::assertSame($alpha, $repository->findById($alpha->getId()));

        $repository->remove($alpha);
        $this->entityManager->clear();

        self::assertNull($repository->findById($alpha->getId()));
        self::assertSame(['beta'], $this->names($repository->findByCreator($this->alice)));
    }

    public function testGrantRepositoryQueriesByResourceGranteeAndCounts(): void
    {
        $repository = $this->service(VaultGrantRepositoryInterface::class);
        self::assertInstanceOf(VaultGrantRepositoryInterface::class, $repository);

        $bobId     = (string) $this->bob->getId();
        $itemGrant = new VaultGrant(VaultResourceType::Item, 'item-1', GranteeType::User, $bobId, VaultPermission::Read, $this->alice);
        $teamGrant = new VaultGrant(VaultResourceType::Item, 'item-2', GranteeType::Team, 'team-a', VaultPermission::Write, $this->alice);
        $dupGrant  = new VaultGrant(VaultResourceType::Item, 'item-2', GranteeType::User, $bobId, VaultPermission::Read, $this->alice);
        $folder    = new VaultGrant(VaultResourceType::Folder, 'folder-1', GranteeType::User, $bobId, VaultPermission::Admin, $this->alice);
        foreach ([$itemGrant, $teamGrant, $dupGrant, $folder] as $grant) {
            $repository->save($grant);
        }

        self::assertCount(2, $repository->findByResource(VaultResourceType::Item, 'item-2'));
        self::assertCount(3, $repository->findByGrantee(GranteeType::User, $bobId));
        self::assertSame($teamGrant, $repository->findOne(VaultResourceType::Item, 'item-2', GranteeType::Team, 'team-a'));
        self::assertNull($repository->findOne(VaultResourceType::Item, 'item-2', GranteeType::Team, 'team-b'));
        self::assertSame($folder, $repository->findById($folder->getId()));

        self::assertSame([], $repository->countByResources(VaultResourceType::Item, []));
        $counts = $repository->countByResources(VaultResourceType::Item, ['item-1', 'item-2', 'item-3']);
        self::assertSame(['item-1' => 1, 'item-2' => 2], $counts);

        // User grants and team grants merge without duplicates; other resource types are ignored.
        $itemIds = $repository->findGrantedResourceIds($this->bob, VaultResourceType::Item, ['team-a', 'team-z']);
        sort($itemIds);
        self::assertSame(['item-1', 'item-2'], $itemIds);
        self::assertSame(['folder-1'], $repository->findGrantedResourceIds($this->bob, VaultResourceType::Folder));
        self::assertSame([], $repository->findGrantedResourceIds(new User(), VaultResourceType::Folder, ['team-a']));

        $repository->remove($teamGrant);
        $this->entityManager->clear();

        self::assertNull($repository->findById($teamGrant->getId()));
    }

    public function testTagRepositoryFindsByCreatorAndName(): void
    {
        $repository = $this->service(VaultTagRepositoryInterface::class);
        self::assertInstanceOf(VaultTagRepositoryInterface::class, $repository);

        $work = new VaultTag('work', $this->alice);
        $home = new VaultTag('home', $this->alice);
        $bobs = new VaultTag('work', $this->bob);
        foreach ([$work, $home, $bobs] as $tag) {
            $repository->save($tag);
        }

        self::assertSame(['home', 'work'], $this->names($repository->findByCreator($this->alice)));
        self::assertSame($work, $repository->findOneByCreatorAndName($this->alice, 'work'));
        self::assertSame($bobs, $repository->findOneByCreatorAndName($this->bob, 'work'));
        self::assertNull($repository->findOneByCreatorAndName($this->bob, 'home'));
        self::assertSame($home, $repository->findById($home->getId()));

        $repository->remove($home);
        $this->entityManager->clear();

        self::assertNull($repository->findById($home->getId()));
    }

    public function testSettingsRepositorySavesAndReloadsScope(): void
    {
        $repository = $this->service(VaultSettingsRepositoryInterface::class);
        self::assertInstanceOf(VaultSettingsRepositoryInterface::class, $repository);

        self::assertNull($repository->findByScope());

        $settings = new VaultSettings(VaultSettings::DEFAULT_SCOPE, ['max_attachment_bytes' => 1000]);
        $repository->save($settings);

        // A direct SQL change is visible: the query refreshes the managed entity (HINT_REFRESH).
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE ' . $this->entityManager->getClassMetadata(VaultSettings::class)->getTableName() . ' SET config_values = ? WHERE scope = ?',
            ['{"max_attachment_bytes":2000}', VaultSettings::DEFAULT_SCOPE],
        );

        $reloaded = $repository->findByScope();
        self::assertSame($settings, $reloaded);
        self::assertSame(['max_attachment_bytes' => 2000], $reloaded->getValues());
        self::assertNull($repository->findByScope('other'));
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertIsObject($service);

        return $service;
    }

    /**
     * @param list<VaultFolder|VaultTag> $entities
     *
     * @return list<string>
     */
    private function names(array $entities): array
    {
        return array_map(static fn (VaultFolder|VaultTag $entity): string => $entity->getName(), $entities);
    }
}
