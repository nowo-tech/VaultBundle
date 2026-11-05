<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Dto\VaultBrowserExtensionLoginMatch;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultItemChangeEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultGrantRepository;
use Nowo\VaultBundle\Repository\DoctrineOrmVaultItemRepository;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Service\VaultBrowserExtensionLoginResolver;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantExpiryService;
use Nowo\VaultBundle\Service\VaultGrantResult;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultItemUpdater;
use Nowo\VaultBundle\Service\VaultPayloadReencryptionService;
use Nowo\VaultBundle\Service\VaultScopeItemLister;
use Nowo\VaultBundle\Service\VaultSharedItemResolver;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ShareRevocationSubscriber;
use Nowo\VaultBundle\Tests\App\EventSubscriber\VaultEventRecorderSubscriber;
use Nowo\VaultBundle\Tests\App\ScopesKernel;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

use function array_filter;
use function array_map;
use function array_values;
use function base64_encode;
use function bin2hex;
use function file_put_contents;
use function json_decode;
use function random_bytes;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * 1.16.0 gaps 1 and 2 on the scopes kernel (deny_status 404, MockClock, temporary grants in the share form): scope
 * ownership, the membership gate, grant expiry on every read path, the purge, race-safe revokes and the scope pages.
 */
final class VaultScopesEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const SCOPE = 'org-1';

    protected static function getKernelClass(): string
    {
        return ScopesKernel::class;
    }

    protected function setUp(): void
    {
        StaticTestClock::reset();
        TestScopeAccessResolver::$members     = [];
        TestScopeAccessResolver::$calls       = 0;
        VaultEventRecorderSubscriber::$events = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testScopeRowsKeepTheOwnerAndRefuseCrossOwnerFoldersAndTags(): void
    {
        $this->bootClient();
        $alice = $this->createUser('alice@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, true, true, true));

        $folder = $this->folders()->createInScope($this->managed($alice), 'Ops', self::SCOPE);
        $item   = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, ['password' => 'p'], $folder);
        $this->tags()->syncItemTags($item, $this->managed($alice), ['prod', 'prod ', '']);

        self::assertSame(self::SCOPE, $item->getOwnerScope());
        self::assertSame(self::SCOPE, $folder->getOwnerScope());
        self::assertSame(['prod'], $item->getTagNames());
        self::assertSame(self::SCOPE, $item->getTags()->first() instanceof VaultTag ? $item->getTags()->first()->getOwnerScope() : null);
        self::assertCount(1, $this->tags()->listForScope(self::SCOPE));
        self::assertSame([], $this->tags()->listForCreator($this->managed($alice)), 'Scope tags are not personal tags.');
        self::assertCount(1, $this->folders()->listForScope(self::SCOPE));

        $personalFolder = $this->createFolder($alice, 'Mine');
        try {
            $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'X', self::SCOPE, [], $personalFolder);
            self::fail('A personal folder must be refused for a scope item.');
        } catch (VaultOwnerException) {
        }

        try {
            $this->folders()->createInScope($this->managed($alice), 'Child', self::SCOPE, $personalFolder);
            self::fail('A personal parent must be refused for a scope folder.');
        } catch (VaultOwnerException) {
        }

        $this->member($alice, new VaultScopeAccess(VaultPermission::Read));
        self::getContainer()->get('services_resetter')->reset();
        $this->expectException(VaultAccessDeniedException::class);
        $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'No create', self::SCOPE, []);
    }

    public function testScopeItemSurvivesItsCreatorAndPersonalItemsStillCascade(): void
    {
        $this->bootClient();
        $alice = $this->createUser('alice@example.com');
        $bob   = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $scopeItem = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, []);
        $personal  = $this->createItem($alice, 'Mine');
        $grant     = $this->grants()->grant(VaultResourceType::Item, $scopeItem->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $ids       = [$scopeItem->getId(), $personal->getId(), $grant->getId()];

        $connection = $this->em()->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$alice->getId()]);
        $this->em()->clear();

        $survivor = $this->em()->find(VaultItem::class, $ids[0]);
        self::assertInstanceOf(VaultItem::class, $survivor);
        self::assertNull($survivor->getCreatedBy());
        self::assertSame(self::SCOPE, $survivor->getOwnerScope());
        self::assertNull($this->em()->find(VaultItem::class, $ids[1]), 'Personal items still cascade (BC).');
        $kept = $this->em()->find(VaultGrant::class, $ids[2]);
        self::assertInstanceOf(VaultGrant::class, $kept, 'Grants survive their grantor (created_by SET NULL).');
        self::assertNull($kept->getCreatedBy());
    }

    public function testMembershipGateEndsAccessOnTheNextRequestGrantsIncluded(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $this->member($bob, new VaultScopeAccess(null));
        $item = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, ['username' => 'u', 'password' => 'p', 'websites' => ['https://stripe.example']]);
        $this->grants()->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));

        $client->loginUser($bob);
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseIsSuccessful();
        $client->request('GET', '/tools/vault/shared');
        self::assertStringContainsString('Stripe', (string) $client->getResponse()->getContent());
        self::assertCount(1, $this->logins($bob, 'stripe.example'));

        // Bob leaves the scope: the very next request denies everything, his direct grant included.
        unset(TestScopeAccessResolver::$members[(string) $bob->getId()]);
        self::getContainer()->get('services_resetter')->reset();
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_' . $item->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/tools/vault/shared');
        self::assertStringNotContainsString('Stripe', (string) $client->getResponse()->getContent());
        self::getContainer()->get('services_resetter')->reset();
        self::assertSame([], $this->logins($bob, 'stripe.example'));
    }

    public function testExpiredGrantDeniesEveryReadPathWithoutPurge(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $item   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['websites' => ['https://mail.example']]);
        $result = $this->grants()->grantAs($this->managed($alice), VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->clock()->now()->modify('+1 hour'));
        self::assertSame(VaultGrantResult::Created, $result);

        $client->loginUser($bob);
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseIsSuccessful();

        $this->clock()->modify('+1 hour');
        $client->request('GET', '/tools/vault/items/' . $item->getId());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/reveal/password', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf($client, 'vault_reveal_' . $item->getId())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('GET', '/tools/vault/shared');
        self::assertStringNotContainsString('Mail', (string) $client->getResponse()->getContent());
        self::assertSame([], $this->logins($bob, 'mail.example'));
        self::assertSame([], self::getContainer()->get(VaultSharedItemResolver::class)->resolve($this->managed($bob)));
        $managedItem = $this->findItem($item->getId());
        self::assertNotNull($managedItem);
        self::assertSame([$item->getId() => null], self::getContainer()->get(VaultAccessGuard::class)->resolvePermissionMap($this->managed($bob), [$managedItem]));
        self::assertCount(1, self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Item, $item->getId()), 'Not purged: enforcement never waits for the purge.');
    }

    public function testPurgeEmitsEachExpiryOnceAndSparesExtendedGrants(): void
    {
        $this->bootClient();
        $alice = $this->createUser('alice@example.com');
        $item  = $this->createItem($alice, 'Mail');
        $soon  = $this->clock()->now()->modify('+10 minutes');
        foreach (['10', '11', '12'] as $grantee) {
            $this->grants()->grantAs($this->managed($alice), VaultResourceType::Item, $item->getId(), GranteeType::User, $grantee, VaultPermission::Read, $soon);
        }
        $this->clock()->modify('+1 hour');
        VaultEventRecorderSubscriber::$events = [];

        $dry = $this->expiry()->purgeExpired(dryRun: true);
        self::assertSame(3, $dry->expired);
        self::assertSame([], VaultEventRecorderSubscriber::$events, 'Dry-run writes nothing and emits nothing.');

        // A second purger runs while the first holds its selected batch; one grant is extended meanwhile.
        $extended = self::getContainer()->get(VaultGrantRepositoryInterface::class)->findOne(VaultResourceType::Item, $item->getId(), GranteeType::User, '12');
        self::assertInstanceOf(VaultGrant::class, $extended);
        $first = $this->expiry()->purgeExpired(onBatchSelected: function (array $ids) use ($alice, $item): void {
            self::assertCount(3, $ids);
            $this->grants()->grantAs($this->managed($alice), VaultResourceType::Item, $item->getId(), GranteeType::User, '12', VaultPermission::Read, $this->clock()->now()->modify('+1 day'));
            self::assertSame(1, $this->expiry()->purgeExpired(limit: 1)->expired);
        });

        self::assertSame(1, $first->expired);
        self::assertSame(['expired' => 1, 'dry_run' => false], $first->toArray());
        $expired = array_values(array_filter(VaultEventRecorderSubscriber::$events, static fn (array $e): bool => $e[0] === VaultEvents::GRANT_EXPIRED));
        self::assertCount(2, $expired, 'Each expired grant emits GRANT_EXPIRED exactly once.');
        self::assertInstanceOf(VaultGrantChangeEvent::class, $expired[0][1]);
        self::assertNull($expired[0][1]->getActorId());
        self::assertSame('user:' . $alice->getId(), $expired[0][1]->getOwnerKey());
        self::assertSame(VaultGrantChangeEvent::REASON_EXPIRED, $expired[0][1]->getReason());
        self::assertCount(1, self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Item, $item->getId()));
        self::assertSame(0, $this->expiry()->purgeExpired()->expired);
    }

    public function testPurgeCommandAndRevokingAnExpiredGrant(): void
    {
        $this->bootClient();
        $alice = $this->createUser('alice@example.com');
        $item  = $this->createItem($alice, 'Mail');
        $this->grants()->grantAs($this->managed($alice), VaultResourceType::Item, $item->getId(), GranteeType::User, '20', VaultPermission::Read, $this->clock()->now()->modify('+10 minutes'));
        $this->grants()->grantAs($this->managed($alice), VaultResourceType::Item, $item->getId(), GranteeType::User, '21', VaultPermission::Read, $this->clock()->now()->modify('+10 minutes'));
        $this->clock()->modify('+1 hour');
        VaultEventRecorderSubscriber::$events = [];

        // Revoking an expired, not yet purged grant: GRANT_REVOKED only, never GRANT_EXPIRED.
        $grant = self::getContainer()->get(VaultGrantRepositoryInterface::class)->findOne(VaultResourceType::Item, $item->getId(), GranteeType::User, '20');
        self::assertInstanceOf(VaultGrant::class, $grant);
        self::assertTrue($this->grants()->revokeAs($this->managed($alice), $grant));
        self::assertFalse($this->grants()->revokeAs($this->managed($alice), $grant), 'A second revoke finds nothing to delete.');

        $application = new Application(self::$kernel);
        $dry         = new CommandTester($application->find('nowo:vault:grants:purge-expired'));
        self::assertSame(0, $dry->execute(['--dry-run' => true]));
        self::assertSame(['expired' => 1, 'dry_run' => true], json_decode($dry->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        $run = new CommandTester($application->find('nowo:vault:grants:purge-expired'));
        self::assertSame(0, $run->execute(['--batch-size' => '1', '--limit' => '5']));
        self::assertSame(['expired' => 1, 'dry_run' => false], json_decode($run->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame([VaultEvents::GRANT_REVOKED, VaultEvents::GRANT_EXPIRED], VaultEventRecorderSubscriber::names());

        $check = new CommandTester($application->find('nowo:vault:schema:check-downgrade'));
        self::assertSame(0, $check->execute([]));
        self::assertSame(['safe' => true, 'scope_items' => 0, 'scope_folders' => 0, 'scope_tags' => 0, 'temporary_grants' => 0], json_decode($check->getDisplay(), true, 512, JSON_THROW_ON_ERROR));
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, []);
        self::assertSame(1, $check->execute([]));
        self::assertStringContainsString('"scope_items":1', $check->getDisplay());
    }

    public function testRotationAndTitleChangesKeepSecretChangedAt(): void
    {
        $this->bootClient();
        $alice   = $this->createUser('alice@example.com');
        $item    = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => 'one']);
        $created = $item->getSecretChangedAt();
        self::assertNotNull($created);

        $this->clock()->modify('+1 day');
        $updater = self::getContainer()->get(VaultItemUpdater::class);
        $payload = ['username' => 'u', 'password' => 'one', 'websites' => [], 'note' => ''];
        $updater->update($item, 'Mail renamed', $payload, null, $this->managed($alice));
        self::assertSame($created->format('Y-m-d H:i:s'), $this->findItem($item->getId())?->getSecretChangedAt()?->format('Y-m-d H:i:s'), 'A title change is not a secret change.');

        $updater->update($item, 'Mail renamed', ['password' => 'two'] + $payload, null, $this->managed($alice));
        $changed = $this->findItem($item->getId())?->getSecretChangedAt();
        self::assertEquals($this->clock()->now()->format('Y-m-d H:i:s'), $changed?->format('Y-m-d H:i:s'));
        $kinds = array_map(static fn (array $e): string => $e[1] instanceof VaultItemChangeEvent ? $e[1]->getKind() . ':' . ($e[1]->isSecretChanged() ? '1' : '0') : '', VaultEventRecorderSubscriber::$events);
        self::assertSame(['created:1', 'updated:0', 'updated:1'], $kinds);

        $before = $this->findItem($item->getId());
        self::assertNotNull($before);
        $updatedAt = $before->getUpdatedAt()->format('Y-m-d H:i:s');
        self::getContainer()->get(VaultPayloadReencryptionService::class)->reencrypt(SodiumVaultPayloadCryptographerTestKey::VALID, base64_encode(str_repeat("\x01", 32)));
        $after = $this->findItem($item->getId());
        self::assertSame($updatedAt, $after?->getUpdatedAt()->format('Y-m-d H:i:s'), 'Rotation does not touch updated_at.');
        self::assertEquals($changed?->format('Y-m-d H:i:s'), $after?->getSecretChangedAt()?->format('Y-m-d H:i:s'), 'Rotation does not touch secret_changed_at.');
    }

    public function testPurgingAnItemDeletesItsGrantsWithEvents(): void
    {
        $this->bootClient();
        $alice                                = $this->createUser('alice@example.com');
        $item                                 = $this->createItem($alice, 'Mail');
        $grant                                = $this->grants()->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, '30', VaultPermission::Read, $this->managed($alice));
        $id                                   = $item->getId();
        VaultEventRecorderSubscriber::$events = [];

        $trash = self::getContainer()->get(VaultTrashService::class);
        $trash->moveItemToTrash($item, $this->managed($alice));
        $trash->restoreItem($item, $this->managed($alice));
        $trash->purgeItem($item, $this->managed($alice));

        self::assertNull(self::getContainer()->get(VaultGrantRepositoryInterface::class)->findById($grant->getId()), 'No orphan grant.');
        self::assertSame([VaultEvents::ITEM_CHANGED, VaultEvents::ITEM_CHANGED, VaultEvents::GRANT_REVOKED, VaultEvents::ITEM_CHANGED], VaultEventRecorderSubscriber::names());
        $revoked = VaultEventRecorderSubscriber::$events[2][1];
        self::assertInstanceOf(VaultGrantChangeEvent::class, $revoked);
        self::assertSame(VaultGrantChangeEvent::REASON_RESOURCE_PURGED, $revoked->getReason());
        self::assertSame($id, $revoked->getResourceId());

        $folder = $this->createFolder($alice, 'Docs');
        $this->grants()->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, '31', VaultPermission::Read, $this->managed($alice));
        $managedFolder = self::getContainer()->get(VaultFolderService::class)->find($folder->getId());
        self::assertInstanceOf(VaultFolder::class, $managedFolder);
        $trash->purgeFolder($managedFolder);
        self::assertSame([], self::getContainer()->get(VaultGrantRepositoryInterface::class)->findByResource(VaultResourceType::Folder, $folder->getId()));
    }

    public function testScopeRoutesCheckThePatternAndMembership(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $client->loginUser($bob);

        foreach (['/tools/vault/scopes/Org-1', '/tools/vault/scopes/' . self::SCOPE, '/tools/vault/scopes/' . self::SCOPE . '/items/new/login'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
        }

        // A member with grants only, but no grant: nothing to list.
        $this->member($bob, new VaultScopeAccess(null));
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $item = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, []);
        $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Not granted', self::SCOPE, []);
        $this->grants()->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Stripe', $html);
        self::assertStringNotContainsString('Not granted', $html);
        self::assertCount(0, $crawler->filterXPath('//*[@data-testid="vault-scope-new-login"]'), 'No create action without create.');

        // No create permission: the creation page is denied.
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/login');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $client->request('POST', '/tools/vault/scopes/' . self::SCOPE . '/folders', ['_token' => $this->csrf($client, 'vault_scope_folder_create_' . self::SCOPE), 'name' => 'Ops']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGrantsOnlyMemberSeesOnlyTheFoldersAndTagsOfVisibleItems(): void
    {
        // SEC-004 FQ-L1: folder and tag names are scope metadata, not listed for items the member cannot View.
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $this->member($bob, new VaultScopeAccess(null));
        $folders = self::getContainer()->get(VaultFolderService::class);
        $parent  = $folders->createInScope($this->managed($alice), 'ParentVisible', self::SCOPE);
        $child   = $folders->createInScope($this->managed($alice), 'ChildVisible', self::SCOPE, $parent);
        $hidden  = $folders->createInScope($this->managed($alice), 'FolderMARKincident', self::SCOPE);
        $admined = $folders->createInScope($this->managed($alice), 'AdminedEmpty', self::SCOPE);
        $seen    = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Granted', self::SCOPE, [], $child);
        $secret  = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Not granted', self::SCOPE, [], $hidden);
        $this->tags()->syncItemTags($seen, $this->managed($alice), ['tagseen']);
        $this->tags()->syncItemTags($secret, $this->managed($alice), ['tagmarkroot']);
        $this->grants()->grant(VaultResourceType::Item, $seen->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        $this->grants()->grant(VaultResourceType::Folder, $admined->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Admin, $this->managed($alice));

        $client->loginUser($bob);
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['Granted', 'ChildVisible', 'ParentVisible', 'tagseen', 'AdminedEmpty'] as $shown) {
            self::assertStringContainsString($shown, $html, $shown);
        }
        foreach (['Not granted', 'FolderMARKincident', 'tagmarkroot'] as $absent) {
            self::assertStringNotContainsString($absent, $html, $absent);
        }

        // A member with a baseline still sees every folder and tag.
        $client->loginUser($alice);
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        $html = (string) $client->getResponse()->getContent();
        foreach (['FolderMARKincident', 'tagmarkroot', 'AdminedEmpty'] as $shown) {
            self::assertStringContainsString($shown, $html, $shown);
        }
    }

    public function testMemberCreatesItemsAndFoldersInTheScope(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $client->loginUser($alice);

        $client->request('POST', '/tools/vault/scopes/' . self::SCOPE . '/folders', ['_token' => $this->csrf($client, 'vault_scope_folder_create_' . self::SCOPE), 'name' => ' ']);
        self::assertResponseRedirects('/tools/vault/scopes/' . self::SCOPE);
        $client->request('POST', '/tools/vault/scopes/' . self::SCOPE . '/folders', ['_token' => $this->csrf($client, 'vault_scope_folder_create_' . self::SCOPE), 'name' => 'Ops']);
        self::assertResponseRedirects('/tools/vault/scopes/' . self::SCOPE);
        $folders = self::getContainer()->get(VaultFolderService::class)->listForScope(self::SCOPE);
        self::assertCount(1, $folders);

        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/login?folder=' . $folders[0]->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('visible to every member in the catalogue', (string) $client->getResponse()->getContent());
        $form = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]'           => 'Stripe',
            'vault_item_form[username]'        => 'ops',
            'vault_item_form[password]'        => 'REDACTED-TEST',
            'vault_item_form[catalogueHidden]' => '1',
        ]);
        $values                              = $form->getPhpValues();
        $values['vault_item_form']['tags']   = ['prod'];
        $values['vault_item_form']['folder'] = $folders[0]->getId();
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/tools/vault/scopes/' . self::SCOPE . '?folder=' . $folders[0]->getId());

        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '?q=str');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Stripe', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-scope-new-login"]'));
        $result = self::getContainer()->get(VaultScopeItemLister::class)->list($this->managed($alice), self::SCOPE, tagId: $this->tags()->listForScope(self::SCOPE)[0]->getId());
        self::assertSame(1, $result['total']);
        self::assertTrue($result['items'][0]->isCatalogueHidden());
        self::assertSame(['prod'], $result['items'][0]->getTagNames());
        self::assertSame(VaultPermission::Admin, $result['permissions'][$result['items'][0]->getId()]);

        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/unknown');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/login');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => '']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testWriteMemberEditsContentButNotTheOrganisation(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $this->member($bob, new VaultScopeAccess(VaultPermission::Write));
        $ops  = $this->folders()->createInScope($this->managed($alice), 'Ops', self::SCOPE);
        $dev  = $this->folders()->createInScope($this->managed($alice), 'Dev', self::SCOPE);
        $item = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, ['password' => 'p'], $ops);

        $client->loginUser($bob);
        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('catalogueHidden', (string) $client->getResponse()->getContent());
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Stripe live', 'vault_item_form[folder]' => $dev->getId()]));
        self::assertResponseRedirects('/tools/vault/scopes/' . self::SCOPE);
        $saved = $this->findItem($item->getId());
        self::assertSame('Stripe live', $saved?->getTitle());
        self::assertSame($ops->getId(), $saved?->getFolder()?->getId(), 'A write member cannot move a scope item.');

        $client->loginUser($alice);
        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/edit');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[folder]' => $dev->getId(), 'vault_item_form[catalogueHidden]' => '1']));
        $saved = $this->findItem($item->getId());
        self::assertSame($dev->getId(), $saved?->getFolder()?->getId());
        self::assertTrue($saved?->isCatalogueHidden());
    }

    public function testSharePageGrantsTemporaryAccessAndFlagsExpiredGrants(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $item   = $this->createItem($alice, 'Mail');
        $client->loginUser($alice);

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        $client->submit($crawler->filterXPath('//form[.//*[@name="vault_share[granteeId]"]]')->form([
            'vault_share[granteeType]' => 'user',
            'vault_share[granteeId]'   => (string) $bob->getId(),
            'vault_share[permission]'  => 'read',
            'vault_share[expiresIn]'   => 'P1D',
        ]));
        self::assertResponseRedirects();
        $grant = self::getContainer()->get(VaultGrantRepositoryInterface::class)->findOne(VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId());
        self::assertInstanceOf(VaultGrant::class, $grant);
        self::assertEquals($this->clock()->now()->modify('+1 day')->format('Y-m-d H:i'), $grant->getExpiresAt()?->format('Y-m-d H:i'));

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-grant-expiry"]'));
        self::assertCount(0, $crawler->filterXPath('//*[@data-testid="vault-grant-expired"]'));
        $this->clock()->modify('+2 days');
        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-grant-expired"]'));

        // Refused by the rules: a temporary admin grant (cap: write), and a custom date beyond max_ttl.
        foreach ([['permission' => 'admin', 'expiresIn' => 'P1D'], ['permission' => 'read', 'expiresIn' => 'custom', 'expiresAt' => $this->clock()->now()->modify('+60 days')->format('Y-m-d\TH:i')]] as $case) {
            $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
            $client->submit($crawler->filterXPath('//form[.//*[@name="vault_share[granteeId]"]]')->form([
                'vault_share[granteeType]' => 'user',
                'vault_share[granteeId]'   => '999',
                'vault_share[permission]'  => $case['permission'],
                'vault_share[expiresIn]'   => $case['expiresIn'],
                'vault_share[expiresAt]'   => $case['expiresAt'] ?? '',
            ]));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Access could not be granted.', (string) $client->getResponse()->getContent());
        }

        // Revoke through the UI: revokeAs (Share re-checked, GRANT_REVOKED).
        VaultEventRecorderSubscriber::$events = [];
        $client->request('POST', '/tools/vault/items/' . $item->getId() . '/grants/' . $grant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $grant->getId())]);
        self::assertResponseRedirects('/tools/vault/items/' . $item->getId() . '/share');
        self::assertSame([VaultEvents::GRANT_REVOKED], VaultEventRecorderSubscriber::names());
    }

    public function testRevokeRoutesAnswerTheDenyStatusWhenShareIsLostMeanwhile(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $folder = $this->createFolder($alice, 'Docs');
        $grant  = $this->grants()->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, '40', VaultPermission::Read, $this->managed($alice));
        $client->loginUser($alice);

        $client->request('POST', '/tools/vault/folders/' . $folder->getId() . '/grants/' . $grant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $grant->getId())]);
        self::assertResponseRedirects('/tools/vault/items?folder=' . $folder->getId());
        self::assertNull(self::getContainer()->get(VaultGrantRepositoryInterface::class)->findById($grant->getId()));
    }

    public function testEntityMappingMatchesTheReferenceSchema(): void
    {
        $this->bootClient();
        $schema = $this->em()->getConnection()->createSchemaManager()->introspectSchema();

        $items = $schema->getTable('vault_items');
        self::assertFalse($items->getColumn('creator_id')->getNotnull());
        self::assertFalse($items->getColumn('owner_scope')->getNotnull());
        self::assertSame(128, $items->getColumn('owner_scope')->getLength());
        self::assertFalse($items->getColumn('created_by_id')->getNotnull());
        self::assertFalse($items->getColumn('secret_changed_at')->getNotnull());
        self::assertTrue($items->getColumn('catalogue_hidden')->getNotnull());
        self::assertTrue($items->hasIndex('vault_items_owner_scope_idx'));
        self::assertSame(['owner_scope', 'deleted_at', 'updated_at'], $items->getIndex('vault_items_owner_scope_idx')->getColumns());
        self::assertTrue($items->hasIndex('vault_items_created_by_idx'));

        $folders = $schema->getTable('vault_folders');
        self::assertFalse($folders->getColumn('creator_id')->getNotnull());
        self::assertFalse($folders->getColumn('owner_scope')->getNotnull());
        self::assertFalse($folders->getColumn('created_by_id')->getNotnull());
        self::assertTrue($folders->hasIndex('vault_folders_owner_scope_idx'));

        $tags = $schema->getTable('vault_tags');
        self::assertFalse($tags->getColumn('creator_id')->getNotnull());
        self::assertFalse($tags->getColumn('owner_scope')->getNotnull());
        self::assertTrue($tags->hasIndex('vault_tags_scope_name_unique'));
        self::assertTrue($tags->getIndex('vault_tags_scope_name_unique')->isUnique());

        $grants = $schema->getTable('vault_grants');
        self::assertFalse($grants->getColumn('expires_at')->getNotnull());
        self::assertFalse($grants->getColumn('created_by_id')->getNotnull());
        self::assertTrue($grants->hasIndex('vault_grants_expires_idx'));
        self::assertTrue($grants->hasIndex('vault_grants_grantee_idx'));

        $onDelete = [];
        foreach (['vault_items' => $items, 'vault_folders' => $folders, 'vault_grants' => $grants, 'vault_tags' => $tags] as $name => $table) {
            foreach ($table->getForeignKeys() as $fk) {
                $onDelete[$name . '.' . $fk->getLocalColumns()[0]] = $fk->onDelete();
            }
        }
        self::assertSame('CASCADE', $onDelete['vault_items.creator_id']);
        self::assertSame('SET NULL', $onDelete['vault_items.created_by_id']);
        self::assertSame('CASCADE', $onDelete['vault_folders.creator_id']);
        self::assertSame('SET NULL', $onDelete['vault_folders.created_by_id']);
        self::assertSame('CASCADE', $onDelete['vault_tags.creator_id']);
        self::assertSame('SET NULL', $onDelete['vault_grants.created_by_id']);
    }

    public function testShareOfAScopeItemRefusesNonMembersAndRevokeRechecksShare(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $item = $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, []);
        $client->loginUser($alice);

        $crawler = $client->request('GET', '/tools/vault/items/' . $item->getId() . '/share');
        $client->submit($crawler->filterXPath('//form[.//*[@name="vault_share[granteeId]"]]')->form([
            'vault_share[granteeType]' => 'user',
            'vault_share[granteeId]'   => (string) $bob->getId(),
            'vault_share[permission]'  => 'read',
        ]));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('is not allowed for this resource', (string) $client->getResponse()->getContent());

        $this->member($bob, new VaultScopeAccess(null));
        $grant = $this->grants()->grant(VaultResourceType::Item, $item->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));

        // Share is lost between the page check and the service re-check (one request): the deny status, nothing revoked.
        ShareRevocationSubscriber::arm(1);
        try {
            $client->request('POST', '/tools/vault/items/' . $item->getId() . '/grants/' . $grant->getId() . '/revoke', ['_token' => $this->csrf($client, 'vault_revoke_grant_' . $grant->getId())]);
        } finally {
            ShareRevocationSubscriber::$denyAfter = null;
        }
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNotNull(self::getContainer()->get(VaultGrantRepositoryInterface::class)->findById($grant->getId()));
    }

    public function testScopePagesNeedALoggedInUserAndHandleAttachments(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertNotSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $personal = $this->createFolder($alice, 'Mine');
        $client->loginUser($alice);
        $path = sys_get_temp_dir() . '/vault-scope-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, '%PDF-1.4 test');

        try {
            $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/passport');
            $form    = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Passport']);
            $client->request('POST', $form->getUri(), $form->getPhpValues(), ['attachment' => new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true)]);
            self::assertResponseRedirects('/tools/vault/scopes/' . self::SCOPE);
            $items   = self::getContainer()->get(VaultScopeItemLister::class)->list($this->managed($alice), self::SCOPE)['items'];
            $payload = self::getContainer()->get(VaultPayloadCryptographerInterface::class)->decrypt($items[0]->getCiphertext());
            self::assertSame('scan.pdf', $payload['attachmentName']);

            // Another owner's folder is not a valid choice: 422.
            $crawler                             = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/login');
            $form                                = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'X']);
            $values                              = $form->getPhpValues();
            $values['vault_item_form']['folder'] = $personal->getId();
            $client->request('POST', $form->getUri(), $values);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

            // Too large for max_attachment_bytes (512 000 by default).
            file_put_contents($path, str_repeat('x', 600_000));
            $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/items/new/passport');
            $form    = $crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Big']);
            $client->request('POST', $form->getUri(), $form->getPhpValues(), ['attachment' => new UploadedFile($path, 'big.pdf', 'application/pdf', null, true)]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        } finally {
            @unlink($path);
        }
    }

    public function testRepositoryEdgeCases(): void
    {
        $this->bootClient();
        $alice = $this->createUser('alice@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $folder = $this->folders()->createInScope($this->managed($alice), 'Ops', self::SCOPE);
        $this->creator()->createInScope($this->managed($alice), VaultItemType::Login, 'In folder', self::SCOPE, [], $folder);
        $this->creator()->createInScope($this->managed($alice), VaultItemType::SecureNote, 'Note', self::SCOPE, []);

        /** @var DoctrineOrmVaultItemRepository $items */
        $items = self::getContainer()->get(VaultItemRepositoryInterface::class);
        self::assertSame(['items' => [], 'total' => 0], $items->paginateByScope(self::SCOPE, [], null, null, null, 0, 10));
        self::assertSame(1, $items->paginateByScope(self::SCOPE, null, $folder->getId(), null, null, 0, 10)['total']);
        self::assertCount(1, $items->findActiveByScope(self::SCOPE, VaultItemType::SecureNote));
        self::assertCount(2, $items->findActiveByScope(self::SCOPE));

        /** @var DoctrineOrmVaultGrantRepository $grants */
        $grants = self::getContainer()->get(VaultGrantRepositoryInterface::class);
        $now    = $this->clock()->now();
        self::assertSame([], $grants->findActiveForGrantees([], [], '1', [], $now));
        self::assertSame([], $grants->findActiveForGrantees(['x'], [], null, [], $now));
        $team = $this->grants()->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::Team, 'ops', VaultPermission::Read, $this->managed($alice));
        self::assertCount(1, $grants->findActiveForGrantees([], [$folder->getId()], null, ['ops'], $now));

        // An update of a row deleted meanwhile reports false and detaches the stale entity.
        $managed = $grants->findById($team->getId());
        self::assertInstanceOf(VaultGrant::class, $managed);
        $this->em()->getConnection()->executeStatement('DELETE FROM vault_grants WHERE id = ?', [$team->getId()]);
        self::assertFalse($grants->updatePermissionAndExpiry($team->getId(), VaultPermission::Write, null));
        self::assertFalse($this->em()->contains($managed));
    }

    private function member(User $user, VaultScopeAccess $access): void
    {
        TestScopeAccessResolver::$members[(string) $user->getId()][self::SCOPE] = $access;
    }

    /**
     * @return list<VaultBrowserExtensionLoginMatch>
     */
    private function logins(User $user, string $domain): array
    {
        return self::getContainer()->get(VaultBrowserExtensionLoginResolver::class)->resolveForDomain($this->managed($user), $domain);
    }

    private function clock(): StaticTestClock
    {
        /** @var StaticTestClock $clock */
        $clock = self::getContainer()->get('test.mock_clock');

        return $clock;
    }

    private function creator(): VaultItemCreator
    {
        return self::getContainer()->get(VaultItemCreator::class);
    }

    private function folders(): VaultFolderService
    {
        return self::getContainer()->get(VaultFolderService::class);
    }

    private function tags(): VaultTagService
    {
        return self::getContainer()->get(VaultTagService::class);
    }

    private function grants(): VaultGrantService
    {
        return self::getContainer()->get(VaultGrantService::class);
    }

    private function expiry(): VaultGrantExpiryService
    {
        return self::getContainer()->get(VaultGrantExpiryService::class);
    }
}
