<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Dto\VaultCatalogueEntry;
use Nowo\VaultBundle\Dto\VaultCatalogueQuery;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultCatalogueAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFieldRevealCheckEvent;
use Nowo\VaultBundle\Event\VaultItemRevealedEvent;
use Nowo\VaultBundle\Event\VaultRevealThrottledEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultBrowserExtensionLoginResolver;
use Nowo\VaultBundle\Service\VaultCatalogue;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Doctrine\QueryCounterMiddleware;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ScopesKernelListeners;
use Nowo\VaultBundle\Tests\App\ScopesKernel;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function base64_encode;
use function bin2hex;
use function json_decode;
use function json_encode;
use function random_bytes;
use function rawurlencode;
use function serialize;
use function str_repeat;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * 1.16.0 gaps 3 and 4 on the scopes kernel: the metadata-only catalogue, the reveal envelope, copy, attachment
 * download, field veto, audit-or-refuse, the reveal rate limits, the response headers and the disabled profiler.
 */
final class VaultRevealCatalogueEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const SCOPE = 'org-1';

    /** @var list<array{0: string, 1: Event}> */
    private static array $events = [];

    protected static function getKernelClass(): string
    {
        return ScopesKernel::class;
    }

    protected function setUp(): void
    {
        StaticTestClock::reset();
        TestScopeAccessResolver::$members = [];
        self::$events                     = [];
        ScopesKernelListeners::reset();
    }

    protected function tearDown(): void
    {
        ScopesKernelListeners::reset();
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testRevealEnvelopeCopyAndEventsWithoutValues(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $marker = $this->marker();
        $item   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => $marker, 'note' => '']);
        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_reveal_' . $item->getId());
        $this->listen([VaultEvents::ITEM_REVEALED, VaultEvents::ITEM_COPIED, VaultEvents::ITEM_FIELD_REVEAL_CHECK]);

        $client->enableProfiler();
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseIsSuccessful();
        self::assertNull($client->getProfile(), 'No profile stores a revealed value.');
        self::assertSame(['item' => $item->getId(), 'field' => 'password', 'purpose' => 'reveal', 'encoding' => 'plain', 'value' => $marker], $this->json($client));
        $this->assertSensitiveHeaders($client->getResponse());

        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', ['purpose' => 'copy'], server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertSame('copy', $this->json($client)['purpose']);
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/note', server: ['HTTP_X_CSRF_TOKEN' => $token, 'CONTENT_TYPE' => 'application/json'], content: '{"purpose":"copy"}');
        self::assertSame(['item' => $item->getId(), 'field' => 'note', 'purpose' => 'copy', 'encoding' => 'plain', 'value' => ''], $this->json($client));

        self::assertSame([VaultEvents::ITEM_FIELD_REVEAL_CHECK, VaultEvents::ITEM_REVEALED, VaultEvents::ITEM_FIELD_REVEAL_CHECK, VaultEvents::ITEM_COPIED, VaultEvents::ITEM_FIELD_REVEAL_CHECK, VaultEvents::ITEM_COPIED], array_column(self::$events, 0));
        $copied = self::$events[3][1];
        self::assertInstanceOf(VaultItemRevealedEvent::class, $copied);
        self::assertSame(VaultItemRevealedEvent::PURPOSE_COPY, $copied->getPurpose());
        self::assertSame('user:' . $alice->getId(), $copied->getOwnerKey());
        self::assertNotNull($copied->getOccurredAt());
        foreach (self::$events as [, $event]) {
            if ($event instanceof VaultItemRevealedEvent) {
                self::assertStringNotContainsString($marker, serialize($event));
                self::assertStringNotContainsString($marker, var_export($event, true));
            }
        }
    }

    public function testFieldVetoAndAuditFailureRefuseTheReveal(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $item   = $this->createItem($alice, 'Card', VaultItemType::CreditCard, ['cvv' => '123', 'cardNumber' => '4111']);
        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_reveal_' . $item->getId());

        $this->onEvent(VaultEvents::ITEM_FIELD_REVEAL_CHECK, static function (VaultFieldRevealCheckEvent $event): void {
            if ($event->getField() === 'cvv') {
                self::assertSame('reveal', $event->getPurpose());
                self::assertSame('web', $event->getChannel());
                self::assertSame('alice@example.com', $event->getUser()->getUserIdentifier());
                self::assertSame('Card', $event->getItem()->getTitle());
                $event->deny();
            }
        });
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/cvv', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // No audit, no reveal: a throwing ITEM_REVEALED listener refuses with 503 and no value.
        $this->onEvent(VaultEvents::ITEM_REVEALED, static function (): never {
            throw new RuntimeException('audit store down');
        });
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/cardNumber', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertStringNotContainsString('4111', (string) $client->getResponse()->getContent());
    }

    public function testTheFieldVetoAlsoAppliesToTheExtensionLogins(): void
    {
        // SEC-004 FQ-L2: a host veto on `password` hides it from /logins too (web: 404; extension: sent empty).
        $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $marker = $this->marker();
        $item   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['username' => 'ops', 'password' => $marker, 'websites' => ['https://mail.example']]);
        self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $checks = [];
        $this->onEvent(VaultEvents::ITEM_FIELD_REVEAL_CHECK, static function (VaultFieldRevealCheckEvent $event) use (&$checks, $alice): void {
            $checks[] = [$event->getField(), $event->getPurpose(), $event->getChannel()];
            if ($event->getUser()->getUserIdentifier() !== $alice->getUserIdentifier()) {
                $event->deny();
            }
        });
        $resolver = self::getContainer()->get(VaultBrowserExtensionLoginResolver::class);

        $forBob = $resolver->resolveForDomain($this->managed($bob), 'mail.example');
        self::assertCount(1, $forBob);
        self::assertSame('', $forBob[0]->password);
        self::assertTrue($forBob[0]->passwordWithheld);
        self::assertSame('ops', $forBob[0]->username);
        self::assertSame(['username', 'websites'], $forBob[0]->revealedFields());
        self::assertStringNotContainsString($marker, json_encode($forBob[0]->toArray(), JSON_THROW_ON_ERROR));
        self::assertSame([['password', 'autofill', 'extension']], $checks);

        $forAlice = $resolver->resolveForDomain($this->managed($alice), 'mail.example');
        self::assertSame($marker, $forAlice[0]->password);
        self::assertFalse($forAlice[0]->passwordWithheld);
        self::assertSame(['username', 'password', 'websites'], $forAlice[0]->revealedFields());
        // SEC-004 FQ-I5: the autofill audit event gets the owner key.
        self::assertSame('user:' . $alice->getId(), $forAlice[0]->ownerKey);
    }

    public function testUndecryptablePayloadAnswers503WithAGenericMessage(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $item   = $this->createItem($alice, 'Mail');
        $this->em()->getConnection()->executeStatement('UPDATE vault_items SET ciphertext = ? WHERE id = ?', [base64_encode(str_repeat('x', 64)), $item->getId()]);
        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_reveal_' . $item->getId());

        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame(['error' => 'Vault item could not be decrypted.'], $this->json($client));
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/attachment', server: ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'A login has no attachment.');

        $passport = $this->createItem($alice, 'Passport', VaultItemType::Passport, ['attachmentContent' => base64_encode('x')]);
        $this->em()->getConnection()->executeStatement('UPDATE vault_items SET ciphertext = ? WHERE id = ?', [base64_encode(str_repeat('y', 64)), $passport->getId()]);
        $client->request('POST', '/tools/vault/items/' . $passport->getId() . '/attachment', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_' . $passport->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);

        // Unknown id: 404. Another user's item: the deny status (404 on this kernel). Anonymous: denied.
        $client->request('POST', '/tools/vault/items/00000000-0000-4000-8000-000000000000/attachment', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_00000000-0000-4000-8000-000000000000')]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $bob = $this->createUser('bob@example.com');
        $client->loginUser($bob);
        $client->request('POST', '/tools/vault/items/' . $passport->getId() . '/attachment', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_' . $passport->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->getCookieJar()->clear();
        $client->request('POST', '/tools/vault/items/' . $passport->getId() . '/attachment');
        self::assertNotSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function testRevealRateLimitThrottlesTheThirtyFirstRequest(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $items  = [];
        foreach (['a', 'b', 'c', 'd'] as $title) {
            $items[] = $this->createItem($alice, $title, VaultItemType::Login, ['note' => 'n']);
        }
        $client->loginUser($alice);
        $tokens = [];
        foreach ($items as $item) {
            $tokens[$item->getId()] = $this->csrf($client, 'vault_reveal_' . $item->getId());
        }
        $this->listen([VaultEvents::REVEAL_THROTTLED]);

        // Per item and field: 10 per minute.
        $first = $items[0]->getId();
        for ($i = 0; $i < 10; ++$i) {
            $client->request('POST', '/tools/vault/items/' . $first . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $tokens[$first]]);
            self::assertResponseIsSuccessful();
        }
        $client->request('POST', '/tools/vault/items/' . $first . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $tokens[$first]]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('60', $client->getResponse()->headers->get('Retry-After'));

        // Per user: 30 per minute across items and fields (10 already counted).
        $sent = 10;
        foreach ($items as $item) {
            foreach (['note', 'password'] as $field) {
                if ($item->getId() === $first && $field === 'password') {
                    continue;
                }
                for ($i = 0; $i < 4 && $sent < 30; ++$i, ++$sent) {
                    $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/' . $field, server: ['HTTP_X_CSRF_TOKEN' => $tokens[$item->getId()]]);
                    self::assertResponseIsSuccessful();
                }
            }
        }
        $client->request('POST', '/tools/vault/items/' . $items[3]->getId() . '/reveal/note', server: ['HTTP_X_CSRF_TOKEN' => $tokens[$items[3]->getId()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $windows = array_map(static fn (array $e): string => $e[1] instanceof VaultRevealThrottledEvent ? $e[1]->getWindow() : '', self::$events);
        self::assertSame([VaultRevealThrottledEvent::WINDOW_FIELD, VaultRevealThrottledEvent::WINDOW_MINUTE], $windows);
        self::assertSame((string) $alice->getId(), self::$events[0][1] instanceof VaultRevealThrottledEvent ? self::$events[0][1]->getActorId() : null);
        self::assertSame($first, self::$events[0][1] instanceof VaultRevealThrottledEvent ? self::$events[0][1]->getItemId() : null);
    }

    public function testAttachmentDownloadSanitisesTheName(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $client->loginUser($alice);
        $content = '%PDF ' . $this->marker();

        foreach ([
            '../../x'            => 'x',
            'a"b.pdf'            => 'ab.pdf',
            "scan\x07\n.pdf"     => 'scan.pdf',
            str_repeat('n', 300) => str_repeat('n', 100),
            '..'                 => 'attachment.bin',
            'résumé.pdf'         => 'résumé.pdf',
        ] as $name => $expected) {
            $item  = $this->createItem($alice, 'Doc', VaultItemType::Passport, ['attachmentName' => $name, 'attachmentContent' => base64_encode($content)]);
            $token = $this->csrf($client, 'vault_reveal_' . $item->getId());
            $client->request('POST', '/tools/vault/items/' . $item->getId() . '/attachment', server: ['HTTP_X_CSRF_TOKEN' => $token]);
            self::assertResponseIsSuccessful();
            $response = $client->getResponse();
            self::assertSame($content, $response->getContent());
            self::assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            self::assertStringContainsString("filename*=UTF-8''" . rawurlencode($expected), (string) $response->headers->get('Content-Disposition'));
            self::assertStringStartsWith('attachment; filename="', (string) $response->headers->get('Content-Disposition'));
            $this->assertSensitiveHeaders($response);
        }

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-attachment-download"]'));
        self::assertStringNotContainsString($content, (string) $client->getResponse()->getContent());

        // No CSRF: 403. An empty attachment: 404.
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/attachment');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $empty = $this->createItem($alice, 'Empty', VaultItemType::Passport, ['attachmentContent' => '']);
        $client->request('POST', '/tools/vault/items/' . $empty->getId() . '/attachment', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_' . $empty->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testEveryBundleRouteSendsNoStore(): void
    {
        $client                                                                  = $this->bootClient();
        $alice                                                                   = $this->createUser('alice@example.com');
        TestScopeAccessResolver::$members[(string) $alice->getId()][self::SCOPE] = new VaultScopeAccess(VaultPermission::Admin, true, true, true);
        $item                                                                    = $this->createItem($alice, 'Mail');
        $grant                                                                   = self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, '99', VaultPermission::Read, $this->managed($alice));
        $folder                                                                  = $this->createFolder($alice, 'Docs');
        $client->loginUser($alice);

        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        $seen   = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, 'Nowo\\VaultBundle\\Controller\\')) {
                continue;
            }
            $path   = strtr($route->getPath(), ['{id}' => str_contains($route->getPath(), '/folders/') ? $folder->getId() : $item->getId(), '{grantId}' => $grant->getId(), '{field}' => 'password', '{type}' => 'login', '{scope}' => self::SCOPE]);
            $method = $route->getMethods()[0] ?? 'GET';
            $client->request($method, $path);
            self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'), $name . ' ' . $method . ' ' . $path);
            self::assertSame('0', $client->getResponse()->headers->get('Expires'), $name);
            ++$seen;
        }
        self::assertGreaterThan(25, $seen);
    }

    public function testCatalogueShowsMetadataOnlyWithConstantQueries(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, true, false, true));
        $this->member($bob, new VaultScopeAccess(null, true));
        $marker  = $this->marker();
        $creator = self::getContainer()->get(VaultItemCreator::class);
        $visible = $creator->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe 100%_live', self::SCOPE, ['password' => $marker, 'websites' => ['https://' . $marker . '.example'], 'username' => $marker]);
        self::getContainer()->get(VaultTagService::class)->syncItemTags($visible, $this->managed($alice), ['prod']);
        $hidden = $creator->createInScope($this->managed($alice), VaultItemType::Passport, 'CEO passport', self::SCOPE, ['documentNumber' => $marker]);
        $hidden->setCatalogueHidden(true);
        $trashed = $creator->createInScope($this->managed($alice), VaultItemType::Login, 'Old', self::SCOPE, []);
        $card    = $creator->createInScope($this->managed($alice), VaultItemType::CreditCard, 'Company card', self::SCOPE, ['cvv' => $marker]);
        self::getContainer()->get(VaultTrashService::class)->moveItemToTrash($trashed);
        self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $card->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $this->em()->flush();

        $catalogue = self::getContainer()->get(VaultCatalogue::class);
        $this->em()->clear();
        $page = $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery());
        self::assertSame([], $this->em()->getUnitOfWork()->getIdentityMap()[VaultItem::class] ?? [], 'No VaultItem is hydrated.');
        self::assertSame(2, $page->total);
        $titles = array_map(static fn (VaultCatalogueEntry $e): string => $e->title, $page->entries);
        self::assertEqualsCanonicalizing(['Stripe 100%_live', 'Company card'], $titles);
        $byTitle = array_combine($titles, $page->entries);
        self::assertSame(['prod'], $byTitle['Stripe 100%_live']->tags);
        self::assertFalse($byTitle['Stripe 100%_live']->viewerHasAccess);
        self::assertTrue($byTitle['Company card']->viewerHasAccess);
        self::assertSame('00:00:00', $byTitle['Company card']->updatedOn->format('H:i:s'));
        self::assertSame(self::SCOPE, $byTitle['Company card']->ownerScope);
        self::assertStringNotContainsString($marker, serialize($page));
        self::assertStringNotContainsString('ciphertext', serialize($page));
        self::assertTrue($catalogue->page($this->managed($alice), self::SCOPE, new VaultCatalogueQuery())->entries[0]->viewerHasAccess, 'Baseline read sees everything.');

        // Filters: literal wildcards, type, tag, too short a search (ignored), paging.
        self::assertSame(1, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery('100%_'))->total);
        self::assertSame(0, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery('1%0'))->total);
        self::assertSame(2, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery('x'))->total);
        self::assertSame(1, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery(null, VaultItemType::CreditCard))->total);
        $tagId = self::getContainer()->get(VaultTagService::class)->listForScope(self::SCOPE)[0]->getId();
        self::assertSame(['Stripe 100%_live'], array_map(static fn (VaultCatalogueEntry $e): string => $e->title, $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery(null, null, $tagId))->entries));
        $second = $catalogue->page($this->managed($bob), self::SCOPE, new VaultCatalogueQuery(null, null, null, 3, 1));
        self::assertSame([], $second->entries);
        self::assertSame(2, $second->pageCount());

        // The page.
        $client->loginUser($bob);
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/catalogue?type=login&q=stripe');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Stripe 100%_live', $html);
        self::assertStringNotContainsString($marker, $html);
        self::assertStringNotContainsString('CEO passport', $html);
        self::assertStringNotContainsString('Old', $html);
    }

    public function testCatalogueQueryCountIsConstant(): void
    {
        $this->bootClient();
        $alice = $this->createUser('alice@example.com');
        $bob   = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, true, false, true));
        $this->member($bob, new VaultScopeAccess(null, true));

        $counts = [];
        foreach ([10, 200] as $size) {
            $this->em()->getConnection()->executeStatement('DELETE FROM vault_items');
            for ($i = 0; $i < $size; ++$i) {
                $item = self::getContainer()->get(VaultItemCreator::class)->createInScope($this->managed($alice), VaultItemType::Login, 'I' . $i, self::SCOPE, []);
                if ($i % 3 === 0) {
                    self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
                }
            }
            $this->em()->clear();
            $viewer = $this->managed($bob);
            QueryCounterMiddleware::reset();
            self::getContainer()->get(VaultCatalogue::class)->page($viewer, self::SCOPE, new VaultCatalogueQuery(null, null, null, 1, 500));
            $counts[$size] = QueryCounterMiddleware::$count;
        }

        self::assertSame($counts[10], $counts[200], 'Catalogue queries do not grow with the number of items.');
        self::assertLessThanOrEqual(4, $counts[200]);
    }

    public function testCatalogueAccessChecks(): void
    {
        $client = $this->bootRevealClient();
        $alice  = $this->createUser('alice@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Read, false));
        $client->loginUser($alice);

        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/catalogue');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'No catalogue flag.');

        $catalogue = self::getContainer()->get(VaultCatalogue::class);
        $this->onEvent(VaultEvents::CATALOGUE_ACCESS_CHECK, static function (VaultCatalogueAccessCheckEvent $event): void {
            self::assertSame(self::SCOPE, $event->getScope());
            self::assertSame('alice@example.com', $event->getUser()->getUserIdentifier());
            $event->grant();
        });
        self::assertSame(0, $catalogue->page($this->managed($alice), self::SCOPE, new VaultCatalogueQuery())->total);

        $this->onEvent(VaultEvents::CATALOGUE_ACCESS_CHECK, static fn (VaultCatalogueAccessCheckEvent $event) => $event->deny(), -10);
        try {
            $catalogue->page($this->managed($alice), self::SCOPE, new VaultCatalogueQuery());
            self::fail('A listener deny must win.');
        } catch (VaultAccessDeniedException) {
        }
        $this->expectException(VaultAccessDeniedException::class);
        $catalogue->page($this->managed($alice), 'org-2', new VaultCatalogueQuery());
    }

    /**
     * Fresh kernel, schema and an empty reveal rate-limit pool (persistent on this kernel).
     */
    private function bootRevealClient(): KernelBrowser
    {
        $client = $this->bootClient();
        self::getContainer()->get('test.vault_secret_access_cache')->clear();

        return $client;
    }

    private function member(User $user, VaultScopeAccess $access): void
    {
        TestScopeAccessResolver::$members[(string) $user->getId()][self::SCOPE] = $access;
    }

    /**
     * @param list<string> $names
     */
    private function listen(array $names): void
    {
        foreach ($names as $name) {
            $this->onEvent($name, static function (Event $event) use ($name): void {
                self::$events[] = [$name, $event];
            }, -1000);
        }
    }

    /**
     * Kernel requests reboot the container: the listener lives in a static registry forwarded by a test subscriber.
     */
    private function onEvent(string $name, callable $listener, int $priority = 0): void
    {
        ScopesKernelListeners::add($name, $listener, $priority);
    }

    private function assertSensitiveHeaders(Response $response): void
    {
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function marker(): string
    {
        return 'MARK' . bin2hex(random_bytes(16));
    }
}
