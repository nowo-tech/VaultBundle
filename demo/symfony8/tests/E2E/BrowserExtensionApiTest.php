<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use const JSON_THROW_ON_ERROR;

final class BrowserExtensionApiTest extends WebTestCase
{
    private function prepareClient(): KernelBrowser
    {
        $client        = static::createClient(['environment' => 'test']);
        $entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->rebuildSchema($entityManager);
        $this->seedVaultFixtures($entityManager);

        return $client;
    }

    public function testExtensionLoginAndDomainLoginsFlow(): void
    {
        $client = $this->prepareClient();

        $client->request(
            'POST',
            '/api/vault/extension/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'demo@example.com', 'password' => 'demo'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        /** @var array{token: string} $login */
        $login = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $auth  = ['HTTP_AUTHORIZATION' => 'Bearer ' . $login['token']];

        $client->request('GET', '/api/vault/extension/logins?domain=login.example.com', server: $auth);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        /** @var array{logins: list<array{title: string, username: string, matchScore: int}>} $payload */
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload['logins']);
        self::assertSame('Demo specific login', $payload['logins'][0]['title']);
        self::assertGreaterThan($payload['logins'][1]['matchScore'], $payload['logins'][0]['matchScore']);
    }

    public function testExtensionRejectsInvalidPassword(): void
    {
        $client = $this->prepareClient();

        $client->request(
            'POST',
            '/api/vault/extension/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['username' => 'demo@example.com', 'password' => 'wrong'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $client->getResponse()->getStatusCode());
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
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = new User();
        $user
            ->setEmail('demo@example.com')
            ->setPassword($hasher->hashPassword($user, 'demo'));

        $entityManager->persist($user);
        $entityManager->flush();

        $creator = static::getContainer()->get(VaultItemCreator::class);
        $creator->create(
            VaultItemType::Login,
            'Demo broad login',
            $user,
            [
                'username' => 'broad-user',
                'password' => 'broad-pass',
                'websites' => ['https://example.com'],
            ],
        );
        $creator->create(
            VaultItemType::Login,
            'Demo specific login',
            $user,
            [
                'username' => 'specific-user',
                'password' => 'specific-pass',
                'websites' => ['https://login.example.com'],
            ],
        );
    }
}
