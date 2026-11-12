<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Ports\PortsUserDirectory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function assert;
use function str_contains;
use function strpos;
use function substr;

/**
 * Shared fixtures of the 1.19 variant kernel tests: a fresh schema, the six host users, and the same vault rows written
 * through the vault services (so the 1.18 wiring and the private entity manager write them the same way).
 */
trait VariantKernelSupport
{
    /** Titles of the seeded items. */
    private const TITLES = ['personal' => 'Title-P', 'in folder' => 'Title-PF', 'scope' => 'Title-S'];

    private static function resetSchema(ContainerInterface $container): void
    {
        $doctrine = $container->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager();
        assert($em instanceof EntityManagerInterface);
        $tool     = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    /**
     * @return array<string, UserInterface> e-mail prefix => user (alice, bob, carol, dave, erin, frank)
     */
    private static function seedUsers(ContainerInterface $container, bool $byId): array
    {
        $users = [];
        foreach (PortsUserDirectory::USERS as $email => $id) {
            $name = substr($email, 0, (int) strpos($email, '@'));
            if ($byId) {
                $users[$name] = new DddUser($id, $email);

                continue;
            }
            $user = (new User())->setEmail($email);
            $user->setPassword('x');
            self::hostManager($container)->persist($user);
            $users[$name] = $user;
        }
        if (!$byId) {
            self::hostManager($container)->flush();
        }

        return $users;
    }

    /**
     * Personal item, item in a personal folder and scope item; user, team and folder grants; a tag.
     *
     * @param array<string, UserInterface> $users
     *
     * @return array<string, string> label => item id
     */
    private static function seedVault(ContainerInterface $container, array $users): array
    {
        $creator = $container->get(VaultItemCreator::class);
        $folders = $container->get(VaultFolderService::class);
        $grants  = $container->get(VaultGrantService::class);
        $tags    = $container->get(VaultTagService::class);
        assert($creator instanceof VaultItemCreator && $folders instanceof VaultFolderService && $grants instanceof VaultGrantService && $tags instanceof VaultTagService);

        $alice   = $users['alice'];
        $payload = ['username' => 'u', 'password' => 'REDACTED-TEST', 'websites' => ['https://example.com']];
        $p       = $creator->create(VaultItemType::Login, self::TITLES['personal'], $alice, $payload);
        $folder  = $folders->create('Folder-F', $alice);
        $pf      = $creator->create(VaultItemType::Login, self::TITLES['in folder'], $alice, $payload, $folder);
        $s       = $creator->createInScope($alice, VaultItemType::Login, self::TITLES['scope'], 'org-1', $payload);
        $tags->syncItemTags($p, $alice, ['work']);

        $id = static fn (string $name): string => (string) UserIdResolver::getId($users[$name]);
        $grants->grant(VaultResourceType::Item, $p->getId(), GranteeType::User, $id('bob'), VaultPermission::Read, $alice);
        $grants->grant(VaultResourceType::Item, $p->getId(), GranteeType::Team, 'team-1', VaultPermission::Write, $alice);
        $grants->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, $id('frank'), VaultPermission::Admin, $alice);
        $grants->grant(VaultResourceType::Item, $s->getId(), GranteeType::User, $id('frank'), VaultPermission::Admin, $alice);
        $grants->grant(VaultResourceType::Item, $s->getId(), GranteeType::User, $id('erin'), VaultPermission::Write, $alice);

        return ['personal' => $p->getId(), 'in folder' => $pf->getId(), 'scope' => $s->getId(), 'folder' => $folder->getId()];
    }

    /**
     * The HTTP decisions of every user on the seeded rows: status codes (403 / 404 / 200 / redirect) and which seeded
     * titles each list shows.
     *
     * @param array<string, UserInterface> $users
     * @param array<string, string> $ids
     *
     * @return array<string, string>
     */
    private static function requestMatrix(KernelBrowser $client, array $users, array $ids): array
    {
        $out = [];
        foreach ([...$users, 'anonymous' => null] as $name => $user) {
            $client->restart();
            if ($user instanceof UserInterface) {
                $client->loginUser($user);
            }
            $pages = [
                'view personal'  => '/tools/vault/items/' . $ids['personal'],
                'view in folder' => '/tools/vault/items/' . $ids['in folder'],
                'view scope'     => '/tools/vault/items/' . $ids['scope'],
                'edit personal'  => '/tools/vault/items/' . $ids['personal'] . '/edit',
                'edit scope'     => '/tools/vault/items/' . $ids['scope'] . '/edit',
                'share personal' => '/tools/vault/items/' . $ids['personal'] . '/share',
                'share folder'   => '/tools/vault/folders/' . $ids['folder'] . '/share',
                'new item'       => '/tools/vault/items/new/login',
                'items list'     => '/tools/vault/items',
                'shared list'    => '/tools/vault/shared',
                'scope list'     => '/tools/vault/scopes/org-1',
            ];
            foreach ($pages as $page => $path) {
                $client->request('GET', $path);
                $response = $client->getResponse();
                $content  = (string) $response->getContent();
                $seen     = '';
                foreach (self::TITLES as $label => $title) {
                    $seen .= str_contains($content, $title) ? ' +' . $label : '';
                }
                $out[$name . ' / ' . $page] = $response->getStatusCode() . ($response->isSuccessful() ? $seen : '');
            }
            if ($user instanceof UserInterface) {
                foreach (['personal', 'scope'] as $label) {
                    $token = self::csrfToken($client, 'vault_reveal_' . $ids[$label]);
                    $client->request('POST', '/tools/vault/items/' . $ids[$label] . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $token]);
                    $out[$name . ' / reveal ' . $label] = (string) $client->getResponse()->getStatusCode();
                }
            }
        }

        return $out;
    }

    private static function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        $client->request('GET', '/tools/vault/shared');
        $request   = $client->getRequest();
        $container = $client->getContainer();
        $stack     = $container->get('request_stack');
        assert($stack instanceof RequestStack);
        $stack->push($request);
        try {
            $manager = $container->get('security.csrf.token_manager');
            assert($manager instanceof CsrfTokenManagerInterface);
            $value = $manager->getToken($tokenId)->getValue();
            $request->getSession()->save();

            return $value;
        } finally {
            $stack->pop();
        }
    }

    private static function hostManager(ContainerInterface $container): EntityManagerInterface
    {
        $doctrine = $container->get('doctrine');
        assert($doctrine instanceof ManagerRegistry);
        $em = $doctrine->getManager();
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * The vault's own manager: the private one when it is on, else the application's.
     */
    private static function vaultManager(ContainerInterface $container): EntityManagerInterface
    {
        if ($container->has(VaultEntityManager::class)) {
            $private = $container->get(VaultEntityManager::class);
            assert($private instanceof VaultEntityManager);

            return $private;
        }

        return self::hostManager($container);
    }
}
