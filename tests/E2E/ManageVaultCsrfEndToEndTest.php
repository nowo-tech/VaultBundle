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

    /**
     * REQ-TWIG-005: the manage pages render their controls through Form Types, and those forms post the
     * historical flat names / token ids the controllers validate.
     */
    public function testManagePagesRenderFormTypesThatSubmit(): void
    {
        [$client, $itemId] = $this->prepareAuthenticatedClient();
        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        self::assertCount(1, $crawler->filterXPath('//form[@method="get"]//input[@id="vault-search"][@name="q"]'));
        self::assertCount(1, $crawler->filterXPath('//form[@data-vault-folder-form]//input[@name="name"]'));
        self::assertCount(1, $crawler->filterXPath('//form[@data-vault-folder-form]//input[@name="_token"]'));

        $folderForm = $crawler->filterXPath('//form[@data-vault-folder-form]')->form(['name' => 'Work']);
        $client->submit($folderForm);
        self::assertTrue($client->getResponse()->isRedirect());

        $crawler   = $client->request('GET', '/tools/vault/items');
        $trashForm = $crawler->filterXPath('//form[@action="/tools/vault/items/' . $itemId . '/trash"]');
        self::assertCount(1, $trashForm->filterXPath('//input[@name="_token"]'));
        $client->submit($trashForm->form());
        self::assertTrue($client->getResponse()->isRedirect());

        $crawler = $client->request('GET', '/tools/vault/trash');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filterXPath('//form[@action="/tools/vault/items/' . $itemId . '/restore"]')->form());
        self::assertTrue($client->getResponse()->isRedirect());

        $crawler = $client->request('GET', '/tools/vault');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filterXPath('//input[@type="radio"][@name="vault-gen-mode"]'));
        self::assertCount(1, $crawler->filterXPath('//input[@data-vault-password-output][@name=""]'));
        self::assertCount(1, $crawler->filterXPath('//input[@type="range"][@data-vault-password-length]'));

        $crawler = $client->request('GET', '/tools/vault/items/new/id_card');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filterXPath('//form[@enctype="multipart/form-data"]//input[@type="file"][@name="attachment"]'));

        $client->request('GET', '/tools/vault/items/' . $itemId . '/share');
        self::assertResponseIsSuccessful();
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
