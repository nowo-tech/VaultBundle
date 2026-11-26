<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use DateTimeImmutable;
use Nowo\UserKitBundle\PersonalData\PersonalDataErasureInterface;
use Nowo\UserKitBundle\PersonalData\PersonalDataExporterInterface;
use Nowo\VaultBundle\Bridge\UserKit\VaultUserKitEraser;
use Nowo\VaultBundle\Bridge\UserKit\VaultUserKitExportProvider;
use Nowo\VaultBundle\Dto\VaultErasureResult;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultPersonalDataErasedEvent;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultFolderService;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultPersonalDataExporter;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ScopesKernelListeners;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\App\UserKitKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Nowo\VaultBundle\Tests\Support\GrantSeeder;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

use function array_keys;
use function array_unique;
use function array_values;
use function bin2hex;
use function json_encode;
use function random_bytes;
use function sort;

use const JSON_THROW_ON_ERROR;

/**
 * 2.5.0 (Beacon spec 185 FR-023, REQ-DATA-004 parity): UserKit's erasure and export cover the vault through the
 * `vault` eraser and export section; the erasure joins UserKit's transaction (a later failing eraser rolls the vault
 * rows back, and PERSONAL_DATA_ERASED leaves only after the commit); `personal_data.user_kit: false` registers nothing.
 */
final class VaultUserKitEndToEndTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const SCOPE = 'org-1';

    private string $marker;

    protected static function getKernelClass(): string
    {
        return UserKitKernel::class;
    }

    protected function setUp(): void
    {
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

    public function testUserKitExportHasTheVaultSectionWithEveryErasureStep(): void
    {
        $this->bootClient();
        [$alice] = $this->fixture();

        /** @var PersonalDataExporterInterface $exporter */
        $exporter = self::getContainer()->get('test.user_kit.exporter');
        $document = $exporter->export($this->managed($alice));
        self::assertArrayHasKey(VaultUserKitExportProvider::SECTION, $document['sections']);
        $section = $document['sections'][VaultUserKitExportProvider::SECTION];
        self::assertIsArray($section);
        self::assertArrayNotHasKey('user_id', $section);

        // Parity: every erasure step has its export section, and every section is in the document.
        $steps = array_keys((new VaultErasureResult())->toArray());
        self::assertSame($steps, array_keys(VaultPersonalDataExporter::ERASURE_SECTIONS));
        $sections = array_values(array_unique(VaultPersonalDataExporter::ERASURE_SECTIONS));
        $exported = array_keys($section);
        sort($sections);
        sort($exported);
        self::assertSame($sections, $exported);

        self::assertCount(2, $section['items']);
        self::assertCount(2, $section['grants_on_personal'], 'The grant to Bob and the team grant on her folder.');
        self::assertCount(1, $section['scope_folders_created']);
        self::assertSame(self::SCOPE, $section['scope_folders_created'][0]['owner_scope']);
        self::assertCount(1, $section['extension_tokens']);

        $json = json_encode($document, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->marker, $json, 'Never a secret value.');
        self::assertStringNotContainsString('ciphertext', $json);
        self::assertStringNotContainsString('token_hash', $json);
    }

    public function testUserKitErasureErasesTheVaultAndAnnouncesItAfterTheCommit(): void
    {
        $this->bootClient();
        [$alice, $bob] = $this->fixture();
        $erased        = [];
        ScopesKernelListeners::add(VaultEvents::PERSONAL_DATA_ERASED, static function (VaultPersonalDataErasedEvent $event) use (&$erased): void {
            $erased[] = $event->getUserId();
        });

        /** @var PersonalDataErasureInterface $eraser */
        $eraser = self::getContainer()->get('test.user_kit.eraser');
        $keys   = $eraser->erase($this->managed($alice));
        self::assertSame([VaultUserKitEraser::KEY, 'host_identity'], $keys, 'The vault runs before the host identity eraser.');
        self::assertSame([], $erased, 'Held through UserKit\'s commit, even with events.after_host_commit off (ZCM-L1).');

        $db = $this->em()->getConnection();
        self::assertFalse($db->isTransactionActive());
        $id = (string) $alice->getId();
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ? OR created_by_id = ?', [$id, $id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_folders WHERE creator_id = ? OR created_by_id = ?', [$id, $id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_grants WHERE grantee_id = ? OR created_by_id = ?', [$id, $id]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_extension_tokens WHERE user_id = ?', [$id]));
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [(string) $bob->getId()]), 'Bob keeps his item.');

        // The event was held inside UserKit's transaction and delivered at the next idle point after its commit.
        self::getContainer()->get('event_dispatcher')->dispatch(new TerminateEvent(self::$kernel ?? self::fail(), new Request(), new Response()), 'kernel.terminate');
        self::assertSame([$id], $erased);

        $section = self::getContainer()->get('test.user_kit.exporter')->export($this->managed($alice))['sections'][VaultUserKitExportProvider::SECTION];
        foreach ($section as $name => $rows) {
            self::assertSame([], $rows, $name . ' is empty after the erasure');
        }
    }

    public function testAFailingLaterEraserRollsTheVaultErasureBack(): void
    {
        $this->bootClient();
        [$alice] = $this->fixture();
        $erased  = [];
        ScopesKernelListeners::add(VaultEvents::PERSONAL_DATA_ERASED, static function () use (&$erased): void {
            $erased[] = true;
        });

        /** @var PersonalDataErasureInterface $eraser */
        $eraser = self::getContainer()->get('test.user_kit.eraser');
        try {
            $eraser->erase($this->managed($alice), null, ['fail' => true]);
            self::fail('The host eraser fails.');
        } catch (RuntimeException $exception) {
            self::assertSame('host eraser failed', $exception->getMessage());
        }

        $db = $this->em()->getConnection();
        self::assertFalse($db->isTransactionActive(), 'No nesting level is left open.');
        $id = (string) $alice->getId();
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [$id]), 'The vault rows are back.');
        self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_extension_tokens WHERE user_id = ?', [$id]));
        self::getContainer()->get('event_dispatcher')->dispatch(new TerminateEvent(self::$kernel ?? self::fail(), new Request(), new Response()), 'kernel.terminate');
        self::assertSame([], $erased, 'A rolled-back erasure is never announced.');

        // The next erasure works on the same kernel (worker mode: nothing stale is kept).
        self::assertContains(VaultUserKitEraser::KEY, $eraser->erase($this->managed($alice)));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [$id]));
    }

    public function testAnErasureInsideAHostTransactionIsAnnouncedOnlyAfterTheCommit(): void
    {
        $this->bootClient();
        [$alice] = $this->fixture();
        $id      = (string) $alice->getId();
        $events  = [];
        foreach ([VaultEvents::PERSONAL_DATA_ERASED, VaultEvents::GRANT_REVOKED] as $name) {
            self::getContainer()->get('event_dispatcher')->addListener($name, static function (object $event, string $eventName) use (&$events): void {
                $events[] = $eventName;
            });
        }
        $db     = $this->em()->getConnection();
        $eraser = self::getContainer()->get('test.personal_data_eraser_port');

        // Rollback: nothing is announced, the rows are back.
        $db->beginTransaction();
        self::assertSame(2, $eraser->erase($id)->items);
        self::assertSame([], $events, 'Held while the transaction is open.');
        $db->rollBack();
        $this->terminate();
        self::assertSame([], $events, 'A rolled-back erasure is never announced.');
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [$id]));

        // Commit: announced once, after it (two grants revoked, then the erasure).
        $db->beginTransaction();
        $eraser->erase($id);
        self::assertSame([], $events);
        $db->commit();
        $this->terminate();
        self::assertSame([VaultEvents::GRANT_REVOKED, VaultEvents::GRANT_REVOKED, VaultEvents::GRANT_REVOKED, VaultEvents::PERSONAL_DATA_ERASED], $events);
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM vault_items WHERE creator_id = ?', [$id]));

        // Outside a transaction (no hold needed): announced at once, as before 2.5.0.
        $events = [];
        $eraser->erase($id);
        self::assertSame([VaultEvents::PERSONAL_DATA_ERASED], $events);
    }

    public function testTheSwitchOffRegistersNoBridge(): void
    {
        self::bootKernel(['environment' => 'test_off', 'debug' => false]);
        $container = self::getContainer();

        self::assertFalse($container->has(VaultUserKitEraser::class));
        self::assertFalse($container->has(VaultUserKitExportProvider::class));
        self::ensureKernelShutdown();

        self::bootKernel();
        self::assertTrue(self::getContainer()->has(VaultUserKitEraser::class));
    }

    private function terminate(): void
    {
        self::getContainer()->get('event_dispatcher')->dispatch(new TerminateEvent(self::$kernel ?? self::fail(), new Request(), new Response()), 'kernel.terminate');
    }

    /**
     * Alice: two personal items (one in a folder, with a tag) shared with Bob and a team; a scope item and folder she
     * created; an extension token. Bob: one item shared with Alice.
     *
     * @return array{0: User, 1: User}
     */
    private function fixture(): array
    {
        $alice                                                                   = $this->createUser('alice@example.com');
        $bob                                                                     = $this->createUser('bob@example.com');
        TestScopeAccessResolver::$members[(string) $alice->getId()][self::SCOPE] = new VaultScopeAccess(VaultPermission::Admin, create: true);
        $grants                                                                  = self::getContainer()->get(VaultGrantService::class);
        $folders                                                                 = self::getContainer()->get(VaultFolderService::class);

        $folder = $this->createFolder($alice, 'Work');
        $mail   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => $this->marker], $folder);
        self::getContainer()->get(VaultTagService::class)->syncItemTags($this->findItem($mail->getId()) ?? self::fail(), $this->managed($alice), ['work']);
        $this->createItem($alice, 'Note', VaultItemType::SecureNote, ['content' => $this->marker]);
        GrantSeeder::grant($grants, VaultResourceType::Item, $mail->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));
        GrantSeeder::grant($grants, VaultResourceType::Folder, $folder->getId(), GranteeType::Team, 'team-1', VaultPermission::Read, $this->managed($alice));
        self::getContainer()->get(VaultItemCreator::class)->createInScope($this->managed($alice), VaultItemType::Login, 'Scope', self::SCOPE, ['password' => $this->marker]);
        $folders->createInScope($this->managed($alice), 'Scope folder', self::SCOPE);

        $bobItem = $this->createItem($bob, 'Bob mail');
        GrantSeeder::grant($grants, VaultResourceType::Item, $bobItem->getId(), GranteeType::User, (string) $alice->getId(), VaultPermission::Read, $this->managed($bob));

        $this->em()->persist(new VaultExtensionToken(bin2hex(random_bytes(32)), new DateTimeImmutable('+1 day'), $this->managed($alice)));
        $this->em()->flush();
        $this->em()->clear();

        return [$alice, $bob];
    }
}
