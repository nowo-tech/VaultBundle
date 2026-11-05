<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use DateTimeImmutable;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultPersonalDataErasedEvent;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Support\VaultUserLocator;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ScopesKernelListeners;
use Nowo\VaultBundle\Tests\App\EventSubscriber\VaultEventRecorderSubscriber;
use Nowo\VaultBundle\Tests\App\ScopesKernel;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function json_encode;
use function random_bytes;
use function serialize;
use function sort;
use function usort;
use function var_export;

use const JSON_THROW_ON_ERROR;

/**
 * 1.16.0 gap 6 on the scopes kernel: the metadata-only personal-data exporter and the ordered, idempotent eraser over
 * the six vault tables.
 */
final class VaultPersonalDataEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const SCOPE = 'org-1';

    private string $marker;

    protected static function getKernelClass(): string
    {
        return ScopesKernel::class;
    }

    protected function setUp(): void
    {
        StaticTestClock::reset();
        TestScopeAccessResolver::$members = [];
        ScopesKernelListeners::reset();
        $this->marker = 'MARK' . bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        ScopesKernelListeners::reset();
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testExporterListsMetadataOnly(): void
    {
        $this->bootClient();
        [$alice, $bob, $fixture] = $this->fixture();

        $export = self::getContainer()->get('test.personal_data_exporter')->export((string) $alice->getId());
        $json   = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->marker, $json);
        self::assertStringNotContainsString('ciphertext', $json);
        self::assertStringNotContainsString('token_hash', $json);

        self::assertSame((string) $alice->getId(), $export['user_id']);
        $items = array_column($export['items'], null, 'title');
        self::assertCount(3, $items, 'Personal items, trash included; no scope item.');
        self::assertSame('login', $items['Mail']['type']);
        self::assertSame('Parent', $items['Mail']['folder']);
        self::assertSame(['work'], $items['Mail']['tags']);
        self::assertNotNull($items['Old']['deleted_at']);
        self::assertNotNull($items['Mail']['secret_changed_at']);
        self::assertSame(['Child', 'Parent'], array_column($this->sorted($export['folders'], 'name'), 'name'));
        self::assertSame(['work'], array_column($export['tags'], 'name'));

        $received = array_column($export['grants_received'], null, 'resource_id');
        self::assertSame('Bob mail', $received[$fixture['bobItem']]['title'], 'Title when Alice can View it now.');
        self::assertSame('Bob docs', $received[$fixture['bobFolder']]['title']);
        self::assertNull($received[$fixture['bobTrashed']]['title'], 'No title for a trashed resource.');
        self::assertSame('read', $received[$fixture['bobItem']]['permission']);

        self::assertCount(3, $export['grants_given']);
        self::assertSame(['team', 'user', 'user'], $this->sortedValues(array_column($export['grants_given'], 'grantee_type')));
        self::assertSame([['id' => $fixture['scopeItem'], 'owner_scope' => self::SCOPE]], array_map(static fn (array $r): array => ['id' => $r['id'], 'owner_scope' => $r['owner_scope']], $export['scope_items_created']));
        self::assertCount(1, $export['extension_tokens']);
        self::assertArrayHasKey('last_used_at', $export['extension_tokens'][0]);

        // Without a locatable user (deleted meanwhile), or without the guard, no received-grant title.
        self::assertSame([], self::getContainer()->get('test.personal_data_exporter')->export('999999')['grants_received']);
        $bare = new VaultPersonalDataExporter(self::getContainer()->get('doctrine'), self::getContainer()->get(VaultUserLocator::class));
        self::assertSame([null, null, null], array_column($bare->export((string) $alice->getId())['grants_received'], 'title'));
        self::assertNotSame([], $bob);
    }

    public function testEraserDeletesInOrderAndIsIdempotent(): void
    {
        $this->bootClient();
        [$alice, $bob, $fixture]              = $this->fixture();
        VaultEventRecorderSubscriber::$events = [];
        $erased                               = [];
        ScopesKernelListeners::add(VaultEvents::PERSONAL_DATA_ERASED, static function (VaultPersonalDataErasedEvent $event) use (&$erased): void {
            $erased[] = $event;
        });

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM vault_folders WHERE parent_id IS NOT NULL'), 'Child has a parent.');
        $eraser = self::getContainer()->get('test.personal_data_eraser');
        $result = $eraser->erase((string) $alice->getId(), batchSize: 1);
        self::assertSame([
            'grants_received'           => 3,
            'grants_on_personal'        => 2,
            'grants_created_anonymised' => 1,
            'item_tags'                 => 1,
            'items'                     => 3,
            'folders'                   => 2,
            'tags'                      => 1,
            'scope_items_anonymised'    => 1,
            'scope_folders_anonymised'  => 1,
            'extension_tokens'          => 1,
        ], $result->toArray());
        self::assertSame(16, $result->total());

        $revoked = array_values(array_filter(VaultEventRecorderSubscriber::$events, static fn (array $e): bool => $e[0] === VaultEvents::GRANT_REVOKED));
        self::assertCount(5, $revoked);
        foreach ($revoked as [, $event]) {
            self::assertInstanceOf(VaultGrantChangeEvent::class, $event);
            self::assertSame(VaultGrantChangeEvent::REASON_ERASURE, $event->getReason());
            self::assertNull($event->getActorId());
        }
        self::assertCount(1, $erased);
        self::assertSame((string) $alice->getId(), $erased[0]->getUserId());
        self::assertSame($result->toArray(), $erased[0]->getCounts());
        self::assertSame((new StaticTestClock())->now()->getTimestamp(), $erased[0]->getOccurredAt()->getTimestamp());
        self::assertStringNotContainsString($this->marker, serialize($erased[0]) . var_export($result, true));

        // Nothing references Alice any more, except NULLed created_by columns; the scope grant she gave survives.
        $db = $this->em()->getConnection();
        $id = (string) $alice->getId();
        foreach (['SELECT COUNT(*) FROM vault_items WHERE creator_id = ? OR created_by_id = ?', 'SELECT COUNT(*) FROM vault_folders WHERE creator_id = ? OR created_by_id = ?', 'SELECT COUNT(*) FROM vault_grants WHERE grantee_id = ? OR created_by_id = ?'] as $sql) {
            self::assertSame(0, (int) $db->fetchOne($sql, [$id, $id]), $sql);
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_tags WHERE creator_id = ?', [$id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_extension_tokens WHERE user_id = ?', [$id]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_grants WHERE resource_id = ? AND created_by_id IS NULL', [$fixture['scopeItem']]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE id = ? AND created_by_id IS NULL', [$fixture['scopeItem']]));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [(string) $bob->getId()]));

        self::assertSame(0, $eraser->erase($id)->total(), 'A second run touches nothing.');
        self::assertCount(2, $erased);
    }

    /**
     * Alice: personal items Mail (folder Child of Parent, tag work), Doc, Old (trash); grants on Mail (to Bob, and to a
     * team); a scope item and folder she created with a grant she gave to Bob; an extension token. Bob: an item, a
     * trashed item and a folder shared with Alice.
     *
     * @return array{0: User, 1: User, 2: array<string, string>}
     */
    private function fixture(): array
    {
        $alice                                                                   = $this->createUser('alice@example.com');
        $bob                                                                     = $this->createUser('bob@example.com');
        TestScopeAccessResolver::$members[(string) $alice->getId()][self::SCOPE] = new VaultScopeAccess(VaultPermission::Admin, create: true);
        TestScopeAccessResolver::$members[(string) $bob->getId()][self::SCOPE]   = new VaultScopeAccess(VaultPermission::Read);
        $grants                                                                  = self::getContainer()->get(VaultGrantService::class);
        $folders                                                                 = self::getContainer()->get(VaultFolderService::class);

        $parent = $this->createFolder($alice, 'Parent');
        $child  = $folders->create('Child', $this->managed($alice), $this->em()->find(VaultFolder::class, $parent->getId()));
        $mail   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => $this->marker], $parent);
        self::getContainer()->get(VaultTagService::class)->syncItemTags($this->findItem($mail->getId()) ?? self::fail(), $this->managed($alice), ['work']);
        $this->em()->flush();
        $this->createItem($alice, 'Doc', VaultItemType::SecureNote, ['content' => $this->marker], $child);
        $old = $this->createItem($alice, 'Old', VaultItemType::Login, ['password' => $this->marker]);
        self::getContainer()->get(VaultTrashService::class)->moveItemToTrash($this->findItem($old->getId()) ?? self::fail());
        $grants->grant(VaultResourceType::Item, $mail->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $grants->grant(VaultResourceType::Folder, $parent->getId(), GranteeType::Team, 'team-1', VaultPermission::Read, $this->managed($alice));

        $scopeItem = self::getContainer()->get(VaultItemCreator::class)->createInScope($this->managed($alice), VaultItemType::Login, 'Scope', self::SCOPE, ['password' => $this->marker]);
        $folders->createInScope($this->managed($alice), 'Scope folder', self::SCOPE);
        $grants->grant(VaultResourceType::Item, $scopeItem->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));

        $bobItem    = $this->createItem($bob, 'Bob mail');
        $bobTrashed = $this->createItem($bob, 'Bob old');
        $bobFolder  = $this->createFolder($bob, 'Bob docs');
        foreach ([[VaultResourceType::Item, $bobItem->getId()], [VaultResourceType::Item, $bobTrashed->getId()], [VaultResourceType::Folder, $bobFolder->getId()]] as [$type, $resourceId]) {
            $grants->grant($type, $resourceId, GranteeType::User, (string) $alice->getId(), VaultPermission::Read, $this->managed($bob));
        }
        self::getContainer()->get(VaultTrashService::class)->moveItemToTrash($this->findItem($bobTrashed->getId()) ?? self::fail());

        $this->em()->persist(new VaultExtensionToken(bin2hex(random_bytes(32)), new DateTimeImmutable('+1 day'), $this->managed($alice)));
        $this->em()->flush();
        $this->em()->clear();

        return [$alice, $bob, ['bobItem' => $bobItem->getId(), 'bobFolder' => $bobFolder->getId(), 'bobTrashed' => $bobTrashed->getId(), 'scopeItem' => $scopeItem->getId()]];
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private function sortedValues(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, string $key): array
    {
        usort($rows, static fn (array $a, array $b): int => (string) $a[$key] <=> (string) $b[$key]);

        return $rows;
    }
}
