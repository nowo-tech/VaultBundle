<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VaultEntityMappingIntegrationTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    /**
     * @param class-string $entityClass
     */
    #[DataProvider('entityClassProvider')]
    public function testEntityManagerReturnsDefaultRepository(string $entityClass): void
    {
        self::assertInstanceOf(EntityRepository::class, $this->entityManager()->getRepository($entityClass));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function entityClassProvider(): iterable
    {
        yield 'item' => [VaultItem::class];
        yield 'folder' => [VaultFolder::class];
        yield 'grant' => [VaultGrant::class];
        yield 'tag' => [VaultTag::class];
        yield 'settings' => [VaultSettings::class];
        yield 'extension token' => [VaultExtensionToken::class];
    }

    public function testUserAssociationsTargetConfiguredUserClass(): void
    {
        $entityManager = $this->entityManager();

        foreach ([
            VaultItem::class           => 'creator',
            VaultFolder::class         => 'creator',
            VaultGrant::class          => 'createdBy',
            VaultTag::class            => 'creator',
            VaultExtensionToken::class => 'user',
        ] as $entityClass => $field) {
            self::assertSame(
                User::class,
                $entityManager->getClassMetadata($entityClass)->getAssociationTargetClass($field),
                $entityClass . '::$' . $field,
            );
        }
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    private function entityManager(): EntityManagerInterface
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
