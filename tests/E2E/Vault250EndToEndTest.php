<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use DateTimeImmutable;
use Nowo\VaultBundle\Dto\VaultCatalogueEntry;
use Nowo\VaultBundle\Dto\VaultCatalogueQuery;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultExtensionTokenRevokedEvent;
use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultItemCreateDeniedException;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultExtensionTokenCommandsInterface;
use Nowo\VaultBundle\Service\VaultItemCommandsInterface;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ScopesKernelListeners;
use Nowo\VaultBundle\Tests\App\ScopesKernel;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultExtensionTokenView;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function array_map;
use function bin2hex;
use function hash;
use function random_bytes;
use function serialize;
use function str_repeat;

/**
 * 2.5.0 on the scopes kernel: the catalogue's creator filter (Beacon spec 185 FR-011), ITEM_CREATE_CHECK on every
 * creation path (FR-020) and the per-user extension token port (FR-022).
 */
final class Vault250EndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const SCOPE = 'org-1';

    protected static function getKernelClass(): string
    {
        return ScopesKernel::class;
    }

    protected function setUp(): void
    {
        TestScopeAccessResolver::$members = [];
        ScopesKernelListeners::reset();
    }

    protected function tearDown(): void
    {
        ScopesKernelListeners::reset();
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testCatalogueFiltersByCreator(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, true, false, true));
        $this->member($bob, new VaultScopeAccess(VaultPermission::Write, true, false, true));
        $creator = self::getContainer()->get(VaultItemCreator::class);
        $creator->createInScope($this->managed($alice), VaultItemType::Login, 'Alice mail', self::SCOPE, []);
        $creator->createInScope($this->managed($alice), VaultItemType::Login, 'Alice bank', self::SCOPE, []);
        $creator->createInScope($this->managed($bob), VaultItemType::Login, 'Bob mail', self::SCOPE, []);
        $this->em()->clear();

        $catalogue = self::getContainer()->get(VaultCatalogue::class);
        $titles    = fn (?string $owner): array => array_map(static fn (VaultCatalogueEntry $e): string => $e->title, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery(ownerId: $owner))->entries);
        self::assertEqualsCanonicalizing(['Alice mail', 'Alice bank', 'Bob mail'], $titles(null));
        self::assertEqualsCanonicalizing(['Alice mail', 'Alice bank'], $titles((string) $alice->getId()));
        self::assertSame(['Bob mail'], $titles((string) $bob->getId()));
        // Unknown, malformed (never reaches the integer column), padded, blank and too long ids match nothing.
        foreach (['999999', '1abc', ' ' . $alice->getId(), $alice->getId() . ' ', '', '   ', str_repeat('1', 129)] as $owner) {
            self::assertSame([], $titles($owner), 'owner ' . $owner);
            self::assertSame(0, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery(ownerId: $owner))->total);
        }
        self::assertSame(1, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery('mail', ownerId: (string) $alice->getId()))->total, 'Combined with the search.');

        // The page: chips, the filter kept by the search form, and the "Created by me" chip of the viewer.
        $client->loginUser($bob);
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/catalogue?owner=' . $bob->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Bob mail', $html);
        self::assertStringNotContainsString('Alice mail', $html);
        self::assertSame('page', $crawler->filterXPath('//*[@data-testid="vault-catalogue-owner-mine"]')->attr('aria-current'));
        self::assertCount(1, $crawler->filterXPath('//input[@type="hidden"][@name="owner"][@value="' . $bob->getId() . '"]'));
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/catalogue?owner=' . $alice->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Selected person', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Alice bank', (string) $client->getResponse()->getContent());
    }

    public function testCreateCheckRefusesOnEveryPathAndCountsTheOwnersItems(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $this->createItem($alice, 'One');
        $old = $this->createItem($alice, 'Old');
        self::getContainer()->get(VaultTrashService::class)->moveItemToTrash($this->findItem($old->getId()) ?? self::fail());
        self::getContainer()->get(VaultItemCreator::class)->createInScope($this->managed($alice), VaultItemType::Login, 'Scope one', self::SCOPE, []);

        // Plan: two items per owner, trash included.
        $seen = [];
        ScopesKernelListeners::add(VaultEvents::ITEM_CREATE_CHECK, static function (VaultItemCreateCheckEvent $event) use (&$seen): void {
            $seen[] = [$event->getOwnerKey(), $event->itemCount(), $event->getType()->value];
            if ($event->itemCount() >= 2) {
                $event->deny('plan_limit');
            }
        });

        // Personal page: refused, the form again with the message (422), nothing written.
        $client->loginUser($alice);
        $crawler = $client->request('GET', '/tools/vault/items/new/login');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]'    => 'Three',
            'vault_item_form[username]' => 'alice',
            'vault_item_form[password]' => 'Redacted-Test-1',
        ]));
        self::assertSame([['user:' . $alice->getId(), 2, 'login']], $seen);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('the limit of your plan is reached', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('role="alert" data-testid="vault-form-errors"', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('plan_limit', (string) $client->getResponse()->getContent(), 'The code never reaches the page.');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [(string) $alice->getId()]));
        self::assertSame([['user:' . $alice->getId(), 2, 'login']], $seen);

        // Scope page: one stored item, so allowed.
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/secure_note');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Scope two']));
        self::assertResponseRedirects();
        self::assertSame(['scope:' . self::SCOPE, 1, 'secure_note'], $seen[1]);

        // Scope page again: two stored, refused.
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/secure_note');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Scope three']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_items WHERE owner_scope = ?', [self::SCOPE]));

        // Host commands: the same answer, with the listener's code.
        /** @var VaultItemCommandsInterface $commands */
        $commands = self::getContainer()->get('test.item_commands');
        try {
            $commands->create(VaultUserRef::ofId((string) $alice->getId()), VaultItemType::Login, 'Via command', ['password' => 'REDACTED-TEST']);
            self::fail('Refused.');
        } catch (VaultItemCreateDeniedException $exception) {
            self::assertSame('plan_limit', $exception->getReason());
        }
        try {
            $commands->createInScope(VaultUserRef::ofId((string) $alice->getId()), self::SCOPE, VaultItemType::Login, 'Via command', []);
            self::fail('Refused.');
        } catch (VaultItemCreateDeniedException) {
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM vault_items WHERE title = 'Via command'"));
        self::assertStringNotContainsString('REDACTED-TEST', serialize($seen));

        // Without a listener: unlimited.
        ScopesKernelListeners::reset();
        self::assertSame('Via command', $commands->create(VaultUserRef::ofId((string) $alice->getId()), VaultItemType::Login, 'Via command', [])->title);
    }

    public function testExtensionTokenPortListsAndRevokesOnlyTheUsersOwnTokens(): void
    {
        $this->bootClient();
        $alice   = $this->createUser('alice@example.com');
        $bob     = $this->createUser('bob@example.com');
        $first   = $this->token($alice, '-2 hours', '+1 day');
        $second  = $this->token($alice, '-1 hour', '-1 minute');
        $bobs    = $this->token($bob, '-1 hour', '+1 day');
        $revoked = [];
        ScopesKernelListeners::add(VaultEvents::EXTENSION_TOKEN_REVOKED, static function (VaultExtensionTokenRevokedEvent $event) use (&$revoked): void {
            $revoked[] = [$event->getUserId(), $event->getTokenIds(), $event->getReason()];
        });

        /** @var VaultExtensionTokenCommandsInterface $tokens */
        $tokens   = self::getContainer()->get('test.extension_token_commands');
        $aliceRef = VaultUserRef::ofId((string) $alice->getId());
        $list     = $tokens->listForUser($aliceRef);
        self::assertSame([$first, $second], array_map(static fn (VaultExtensionTokenView $t): string => $t->id, $list));
        self::assertTrue($list[1]->isExpiredAt(new DateTimeImmutable()));
        self::assertStringNotContainsString('hash', serialize($list));

        // Another user's token, an unknown id and an over-long id: the same false, nothing deleted, no event.
        foreach ([$bobs, '00000000-0000-4000-8000-000000000000', str_repeat('a', 37), ''] as $id) {
            self::assertFalse($tokens->revoke($aliceRef, $id), $id);
        }
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_extension_tokens'));
        self::assertSame([], $revoked);

        self::assertTrue($tokens->revoke($aliceRef, $first));
        self::assertFalse($tokens->revoke($aliceRef, $first), 'Already revoked.');
        self::assertSame([[(string) $alice->getId(), [$first], VaultExtensionTokenRevokedEvent::REASON_ONE]], $revoked);

        self::assertSame(1, $tokens->revokeAll($aliceRef));
        self::assertSame(0, $tokens->revokeAll($aliceRef));
        self::assertSame([(string) $alice->getId(), [$second], VaultExtensionTokenRevokedEvent::REASON_ALL], $revoked[1]);
        self::assertCount(2, $revoked);
        self::assertSame([$bobs], array_map(static fn (VaultExtensionTokenView $t): string => $t->id, $tokens->listForUser(VaultUserRef::ofId((string) $bob->getId()))));

        // An unknown user is refused (a reference by id is looked up again).
        $this->expectException(VaultAccessDeniedException::class);
        $tokens->listForUser(VaultUserRef::ofId('999999'));
    }

    private function token(User $user, string $created, string $expires): string
    {
        $token = new VaultExtensionToken(hash('sha256', bin2hex(random_bytes(16))), new DateTimeImmutable($expires), $this->managed($user));
        (new ReflectionProperty(VaultExtensionToken::class, 'createdAt'))->setValue($token, new DateTimeImmutable($created));
        $this->em()->persist($token);
        $this->em()->flush();
        $id = $token->getId();
        $this->em()->clear();

        return $id;
    }

    private function member(User $user, VaultScopeAccess $access): void
    {
        TestScopeAccessResolver::$members[(string) $user->getId()][self::SCOPE] = $access;
    }
}
