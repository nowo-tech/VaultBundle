<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Shared fixtures for the kernel functional tests (fresh SQLite schema, users, items, CSRF tokens).
 */
trait VaultWebTestTrait
{
    private function bootClient(): KernelBrowser
    {
        $client = self::createClient(['disableReboot' => true]);
        $em     = $this->em();
        $tool   = new SchemaTool($em);
        $meta   = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($meta);
        $tool->createSchema($meta);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function createUser(string $email, string $password = 'secret'): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user   = (new User())->setEmail($email);
        $user->setPassword($hasher->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createItem(User $user, string $title, VaultItemType $type = VaultItemType::Login, array $payload = [], ?VaultFolder $folder = null): VaultItem
    {
        /** @var VaultItemCreator $creator */
        $creator = self::getContainer()->get(VaultItemCreator::class);

        $folder = $folder instanceof VaultFolder ? $this->em()->find(VaultFolder::class, $folder->getId()) : null;

        return $creator->create($type, $title, $this->managed($user), $payload + ['username' => 'u', 'password' => 'REDACTED-TEST', 'websites' => ['https://example.com']], $folder);
    }

    private function createFolder(User $user, string $name): VaultFolder
    {
        /** @var VaultFolderService $folders */
        $folders = self::getContainer()->get(VaultFolderService::class);

        return $folders->create($name, $this->managed($user));
    }

    /**
     * Each kernel request resets the entity manager: re-attach the fixture user before using it in a relation.
     */
    private function managed(User $user): User
    {
        $managed = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }

    private function findItem(string $id): ?VaultItem
    {
        /** @var VaultItemRepositoryInterface $repo */
        $repo = self::getContainer()->get(VaultItemRepositoryInterface::class);
        $item = $repo->findById($id);
        if ($item instanceof VaultItem) {
            $this->em()->refresh($item);
        }

        return $item;
    }

    private function csrf(KernelBrowser $client, string $tokenId): string
    {
        // A fresh GET opens the session the next request will read; the token is then saved into it.
        $client->request('GET', '/tools/vault/shared');
        $request = $client->getRequest();
        $stack   = self::getContainer()->get('request_stack');
        $stack->push($request);
        try {
            /** @var CsrfTokenManagerInterface $manager */
            $manager = self::getContainer()->get('security.csrf.token_manager');
            $value   = $manager->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }
}
