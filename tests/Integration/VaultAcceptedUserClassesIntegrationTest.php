<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\AcceptedUsersKernel;
use Nowo\VaultBundle\Tests\App\Ddd\AppSecurityUser;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_map;

/**
 * Relation mapping (the standard default) with `accepted_user_classes` (1.17.0): a security user that is no Doctrine
 * entity is stored as a reference of `user_class` with its id; a user id given as a string works the same; any other
 * user is refused before Doctrine sees it.
 */
final class VaultAcceptedUserClassesIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $alice;

    protected static function getKernelClass(): string
    {
        return AcceptedUsersKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $tool     = new SchemaTool($em);
        $meta     = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
        $this->alice = (new User())->setEmail('alice@example.com')->setPassword('x');
        $em->persist($this->alice);
        $em->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testAcceptedSecurityUserIsStoredAsAReferenceOfUserClass(): void
    {
        $id      = (int) $this->alice->getId();
        $wrapper = new AppSecurityUser($id, 'alice@example.com');
        $creator = self::getContainer()->get(VaultItemCreator::class);
        self::assertInstanceOf(VaultItemCreator::class, $creator);

        $item = $creator->create(VaultItemType::Login, 'Bank', $wrapper, ['password' => 'p']);
        self::assertInstanceOf(User::class, $item->getCreator(), 'Replaced by a user_class reference at persist.');
        self::assertSame((string) $id, $item->getCreatorId());

        $this->em->clear();
        $repository = self::getContainer()->get(VaultItemRepositoryInterface::class);
        self::assertInstanceOf(VaultItemRepositoryInterface::class, $repository);
        self::assertSame(['Bank'], array_map(static fn (VaultItem $i): string => $i->getTitle(), $repository->findByCreator($wrapper)));
        $stored = $repository->findByCreator($wrapper)[0];
        self::assertSame((string) $id, $stored->getCreatorId());
        self::assertTrue($stored->getOwner()->isUser($wrapper));
    }

    public function testUserIdGivenAsAStringBecomesAReference(): void
    {
        $folders = self::getContainer()->get(VaultFolderService::class);
        self::assertInstanceOf(VaultFolderService::class, $folders);
        $folder = new VaultFolder('Work', (string) $this->alice->getId());
        $this->em->persist($folder);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(['Work'], array_map(static fn (VaultFolder $f): string => $f->getName(), $folders->listForCreator($this->alice)));
        self::assertSame((string) $this->alice->getId(), $folders->listForCreator($this->alice)[0]->getCreatorId());
    }

    public function testUserOfAnotherIdentityDomainIsRefused(): void
    {
        $creator = self::getContainer()->get(VaultItemCreator::class);
        self::assertInstanceOf(VaultItemCreator::class, $creator);
        $repository = self::getContainer()->get(VaultItemRepositoryInterface::class);
        self::assertInstanceOf(VaultItemRepositoryInterface::class, $repository);
        $other = new OtherDomainUser((string) $this->alice->getId(), 'admin@example.com');

        try {
            $repository->findByCreator($other);
            self::fail('Listing with a foreign identity must be refused.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $creator->create(VaultItemType::Login, 'Spoofed', $other, []);
    }
}
