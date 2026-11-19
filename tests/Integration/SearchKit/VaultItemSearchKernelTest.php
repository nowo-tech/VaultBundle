<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\SearchKit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\VaultBundle\Bridge\SearchKit\VaultItemSearchDefinition;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultSearchScopesEvent;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\SearchKitKernel;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

use function array_map;
use function class_exists;
use function json_encode;

/**
 * The group as the extension wires it in a booted kernel: with SearchKit installed, the definition resolves every
 * argument (guard, gates, router, runtime routes, translator) and finds the viewer's own and scope items; without it
 * (PHP 8.2), nothing is registered.
 */
final class VaultItemSearchKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return SearchKitKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestScopeAccessResolver::$members = [];
        self::ensureKernelShutdown();
    }

    public function testTheWiredGroupSearchesOwnAndScopeItemNames(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        if (!class_exists(VaultExtension::SEARCH_KIT_DEFINITION_CLASS)) {
            self::assertFalse($container->has('test.vault_search_definition'), 'Without SearchKit the group is not registered.');

            return;
        }

        $definition = $container->get('test.vault_search_definition');
        self::assertInstanceOf(VaultItemSearchDefinition::class, $definition);
        self::assertSame(50, $definition->maxCandidates());

        $em = $container->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool     = new SchemaTool($em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $alice = (new User())->setEmail('alice@example.com')->setPassword('x');
        $bob   = (new User())->setEmail('bob@example.com')->setPassword('x');
        $em->persist($alice);
        $em->persist($bob);
        $em->flush();

        $creator = $container->get(VaultItemCreator::class);
        self::assertInstanceOf(VaultItemCreator::class, $creator);
        $creator->create(VaultItemType::Login, 'kernelmark own', $alice, ['password' => 'MARKER-SECRET']);
        $creator->create(VaultItemType::Login, 'kernelmark bob', $bob, ['password' => 'x']);
        $em->persist(VaultItem::forScope(VaultItemType::Login, 'kernelmark scope', 'proj-k', $bob, 'ciphertext'));
        $em->flush();
        TestScopeAccessResolver::$members = [(string) $alice->getId() => ['proj-k' => new VaultScopeAccess(VaultPermission::Read)]];

        $events = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $events->addListener(VaultEvents::SEARCH_SCOPES, static fn (VaultSearchScopesEvent $event) => $event->limitTo(['proj-k']));

        $tokens = $container->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokens);
        $tokens->setToken(new UsernamePasswordToken($alice, 'main', $alice->getRoles()));

        $group = (new EntitySearchProvider($definition))->search(new SearchQuery('kernelmark', null, 10, null, false, 'alice@example.com', [], 'en'));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertEqualsCanonicalizing(['kernelmark scope', 'kernelmark own'], array_map(static fn ($item): string => $item->title, $group->items));
        self::assertSame('Vault items', $group->label);
        self::assertStringNotContainsString('MARKER-SECRET', (string) json_encode(array_map(static fn ($item): array => $item->toArray('vault_items'), $group->items)));
    }
}
