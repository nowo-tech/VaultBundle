<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Command\PurgeExtensionTokensCommand;
use Nowo\VaultBundle\Command\ReencryptVaultPayloadsCommand;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VaultPayloadReencryptionIntegrationTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testReencryptCommandRotatesStoredPayloads(): void
    {
        self::bootKernel();
        $container     = self::getContainer();
        $entityManager = $container->get('doctrine')->getManager();
        $this->rebuildSchema($entityManager);

        $oldKey    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $oldCrypto = new SodiumVaultPayloadCryptographer($oldKey);
        $newCrypto = new SodiumVaultPayloadCryptographer($newKey);

        $user = $this->seedUser($entityManager);
        $item = new VaultItem(
            VaultItemType::Login,
            'Rotation test',
            $user,
            $oldCrypto->encrypt([
                'username' => 'rotate-me',
                'password' => 'rotate-secret',
                'websites' => ['https://example.com'],
            ]),
        );
        $entityManager->persist($item);
        $entityManager->flush();
        $cipher = $item->getCiphertext();

        self::assertSame(
            ['username' => 'rotate-me', 'password' => 'rotate-secret', 'websites' => ['https://example.com']],
            $oldCrypto->decrypt($cipher),
        );

        $tester = $this->commandTester('nowo:vault:reencrypt');
        $tester->execute([
            '--old-key' => $oldKey,
            '--new-key' => $newKey,
            '--force'   => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Re-encrypted 1 of 1', $tester->getDisplay());

        $entityManager->clear();

        /** @var VaultItemRepositoryInterface $items */
        $items   = $container->get(VaultItemRepositoryInterface::class);
        $rotated = $items->findByCreator($user)[0];

        self::assertNotSame($cipher, $rotated->getCiphertext());
        self::assertSame(
            ['username' => 'rotate-me', 'password' => 'rotate-secret', 'websites' => ['https://example.com']],
            $newCrypto->decrypt($rotated->getCiphertext()),
        );
    }

    public function testMaintenanceCommandsAreRegisteredInContainer(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->has(PurgeExtensionTokensCommand::class));
        self::assertTrue(self::getContainer()->has(ReencryptVaultPayloadsCommand::class));
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    private function commandTester(string $name): CommandTester
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return new CommandTester($application->find($name));
    }

    private function rebuildSchema(EntityManagerInterface $entityManager): void
    {
        $tool     = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function seedUser(EntityManagerInterface $entityManager): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = (new User())
            ->setEmail('rotate@example.com')
            ->setPassword($hasher->hashPassword(new User(), 'secret'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
