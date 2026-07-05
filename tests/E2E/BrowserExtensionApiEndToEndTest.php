<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

final class BrowserExtensionApiEndToEndTest extends WebTestCase
{
    private function prepareClient(): KernelBrowser
    {
        $client        = self::createClient(['disableReboot' => true]);
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->rebuildSchema($entityManager);
        $this->seedVaultFixtures($entityManager);

        return $client;
    }

    public function testLoginRejectsInvalidCredentials(): void
    {
        $client = $this->prepareClient();
        $client->request(
            'POST',
            '/api/vault/extension/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'alice@example.com', 'password' => 'wrong'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function testFullBrowserExtensionApiFlow(): void
    {
        $client = $this->prepareClient();

        $client->request(
            'POST',
            '/api/vault/extension/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'alice@example.com', 'password' => 'secret'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        /** @var array{token: string, expiresAt: string} $loginPayload */
        $loginPayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame('', $loginPayload['token']);
        self::assertNotSame('', $loginPayload['expiresAt']);

        $token = $loginPayload['token'];
        $auth  = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        $client->request('GET', '/api/vault/extension/me', server: $auth);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
        /** @var array{identifier: string} $mePayload */
        $mePayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('alice@example.com', $mePayload['identifier']);

        $client->request('GET', '/api/vault/extension/logins', server: $auth);
        self::assertSame(Response::HTTP_BAD_REQUEST, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/vault/extension/logins?domain=login.example.com', server: $auth);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        /** @var array{logins: list<array{title: string, username: string, matchScore: int}>} $loginsPayload */
        $loginsPayload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $loginsPayload['logins']);
        self::assertSame('Specific login', $loginsPayload['logins'][0]['title']);
        self::assertSame('specific-user', $loginsPayload['logins'][0]['username']);
        self::assertSame('Broad login', $loginsPayload['logins'][1]['title']);
        self::assertGreaterThan(
            $loginsPayload['logins'][1]['matchScore'],
            $loginsPayload['logins'][0]['matchScore'],
        );

        $client->request('POST', '/api/vault/extension/logout', server: $auth);
        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/vault/extension/me', server: $auth);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function testLoginsRequireBearerToken(): void
    {
        $client = $this->prepareClient();
        $client->request('GET', '/api/vault/extension/logins?domain=example.com');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
    }

    public function testLoginSupportsCorsPreflight(): void
    {
        $client = $this->prepareClient();

        $client->request(
            'OPTIONS',
            '/api/vault/extension/login',
            server: ['HTTP_ORIGIN' => 'chrome-extension://test-extension-id'],
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());

        $client->request(
            'OPTIONS',
            '/api/vault/extension/login',
            server: ['HTTP_ORIGIN' => 'moz-extension://test-extension-id'],
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $client->getResponse()->getStatusCode());
    }

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    private function rebuildSchema(EntityManagerInterface $entityManager): void
    {
        $tool     = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    private function seedVaultFixtures(EntityManagerInterface $entityManager): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user
            ->setEmail('alice@example.com')
            ->setPassword($hasher->hashPassword($user, 'secret'));

        $entityManager->persist($user);
        $entityManager->flush();

        $creator = self::getContainer()->get(VaultItemCreator::class);
        $creator->create(
            VaultItemType::Login,
            'Broad login',
            $user,
            [
                'username' => 'broad-user',
                'password' => 'broad-pass',
                'websites' => ['https://example.com'],
            ],
        );
        $creator->create(
            VaultItemType::Login,
            'Specific login',
            $user,
            [
                'username' => 'specific-user',
                'password' => 'specific-pass',
                'websites' => ['https://login.example.com'],
            ],
        );
    }
}
