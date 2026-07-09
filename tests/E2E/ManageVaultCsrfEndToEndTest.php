<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use const JSON_THROW_ON_ERROR;

final class ManageVaultCsrfEndToEndTest extends WebTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testTrashItemWithoutCsrfIsDenied(): void
    {
        [$client, $itemId] = $this->prepareAuthenticatedClient();

        $client->request('POST', '/tools/vault/items/' . $itemId . '/trash');

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testTrashItemWithValidCsrfSucceeds(): void
    {
        [$client, $itemId] = $this->prepareAuthenticatedClient();
        $token             = $this->csrfToken($client, 'vault_trash_' . $itemId);

        $client->request('POST', '/tools/vault/items/' . $itemId . '/trash', ['_token' => $token]);

        self::assertTrue($client->getResponse()->isRedirect());
    }

    public function testCreateFolderWithoutCsrfIsDenied(): void
    {
        $client = $this->prepareAuthenticatedClient()[0];

        $client->request('POST', '/tools/vault/folders', ['name' => 'Work']);

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    public function testPasswordGenerateWithoutCsrfIsDenied(): void
    {
        $client = $this->prepareAuthenticatedClient()[0];

        $client->request(
            'POST',
            '/tools/vault/password/generate',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['mode' => 'characters', 'length' => 16], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function prepareAuthenticatedClient(): array
    {
        $client        = self::createClient(['disableReboot' => true]);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->rebuildSchema($entityManager);
        $user   = $this->seedUser($entityManager);
        $itemId = $this->seedLoginItem($user);
        $client->loginUser($user);
        $client->request('GET', '/tools/vault/items');

        return [$client, $itemId];
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $stack = self::getContainer()->get('request_stack');
        $stack->push($client->getRequest());
        try {
            /** @var CsrfTokenManagerInterface $manager */
            $manager = self::getContainer()->get('security.csrf.token_manager');

            return $manager->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
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
            ->setEmail('alice@example.com')
            ->setPassword($hasher->hashPassword(new User(), 'secret'));

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function seedLoginItem(User $user): string
    {
        $creator = self::getContainer()->get(VaultItemCreator::class);
        $creator->create(
            VaultItemType::Login,
            'Test login',
            $user,
            [
                'username' => 'user',
                'password' => 'pass',
                'websites' => ['https://example.com'],
            ],
        );

        /** @var VaultItemRepositoryInterface $items */
        $items = self::getContainer()->get(VaultItemRepositoryInterface::class);
        $all   = $items->findByCreator($user);

        self::assertCount(1, $all);

        return $all[0]->getId();
    }
}
