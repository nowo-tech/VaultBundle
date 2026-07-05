<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReencryptVaultCommandTest extends KernelTestCase
{
    public function testReencryptCommandWithPersistNewKey(): void
    {
        self::bootKernel(['environment' => 'test']);

        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->rebuildSchema($entityManager);

        $oldKey    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $newKey    = SodiumVaultPayloadCryptographer::generateKeyBase64();
        $oldCrypto = new SodiumVaultPayloadCryptographer($oldKey);
        $newCrypto = new SodiumVaultPayloadCryptographer($newKey);

        $user = $this->seedUser($entityManager);
        $item = new VaultItem(
            VaultItemType::Login,
            'Demo login',
            $user,
            $oldCrypto->encrypt([
                'username' => 'demo',
                'password' => 'demo-pass',
                'websites' => ['https://example.com'],
            ]),
        );
        $entityManager->persist($item);
        $entityManager->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('nowo:vault:reencrypt'));
        $tester->execute([
            '--old-key'         => $oldKey,
            '--new-key'         => $newKey,
            '--persist-new-key' => true,
            '--force'           => true,
            '--no-interaction'  => true,
        ]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Re-encrypted 1 of 1', $tester->getDisplay());
        self::assertStringContainsString('Stored the new encryption key', $tester->getDisplay());

        $entityManager->clear();

        /** @var VaultItemRepositoryInterface $items */
        $items   = static::getContainer()->get(VaultItemRepositoryInterface::class);
        $rotated = $items->findByCreator($user)[0];

        self::assertSame(
            ['username' => 'demo', 'password' => 'demo-pass', 'websites' => ['https://example.com']],
            $newCrypto->decrypt($rotated->getCiphertext()),
        );
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
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = (new User())
            ->setEmail('reencrypt-demo@example.com')
            ->setPassword($hasher->hashPassword(new User(), 'demo'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
