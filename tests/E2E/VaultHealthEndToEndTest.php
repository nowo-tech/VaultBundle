<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultHealthAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultHealthReportGeneratedEvent;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\Clock\StaticTestClock;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\EventSubscriber\ScopesKernelListeners;
use Nowo\VaultBundle\Tests\App\ScopesKernel;
use Nowo\VaultBundle\Tests\App\Security\TestScopeAccessResolver;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function base64_encode;
use function bin2hex;
use function random_bytes;
use function serialize;

/**
 * 1.16.0 gap 5 on the scopes kernel (health enabled, max_items 3, policy enforced on save): the scope health page,
 * its access checks, the reuse oracle, the size limit and the enforce-on-save policy of the item forms.
 */
final class VaultHealthEndToEndTest extends WebTestCase
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
        ScopesKernelListeners::reset();
    }

    protected function tearDown(): void
    {
        ScopesKernelListeners::reset();
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testHealthPageShowsFlagsOnlyForVisibleItems(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $bob    = $this->createUser('bob@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, health: true, create: true));
        // Bob audits, but sees only what he was granted.
        $this->member($bob, new VaultScopeAccess(null, health: true));
        // Lowercase and digits only: weak under the default policy (3 classes).
        $marker = 'mark' . bin2hex(random_bytes(16));
        $mine   = $this->scopeItem($alice, 'Visible weak', $marker);
        $this->scopeItem($alice, 'Invisible twin', $marker);
        $this->scopeItem($alice, 'Strong', 'Str0ng-Enough-Passw0rd!');
        self::getContainer()->get(VaultGrantService::class)->grant(VaultResourceType::Item, $mine->getId(), GranteeType::User, (string) $bob->getId(), VaultPermission::Read, $this->managed($alice));

        $generated = [];
        ScopesKernelListeners::add(VaultEvents::HEALTH_REPORT_GENERATED, static function (VaultHealthReportGeneratedEvent $event) use (&$generated): void {
            $generated[] = $event;
        });

        $client->loginUser($bob);
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/health');
        self::assertResponseIsSuccessful();
        self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Visible weak', $html);
        self::assertStringNotContainsString('Invisible twin', $html);
        self::assertStringNotContainsString($marker, $html);
        self::assertStringNotContainsString(base64_encode($marker), $html);
        self::assertStringContainsString('1 checked, 2 without access', $crawler->filterXPath('//*[@data-testid="vault-health-summary"]')->text());
        // The oracle: the invisible item holds the same password, yet no reuse is reported.
        self::assertCount(0, $crawler->filterXPath('//*[@data-testid="vault-health-reused"]'));
        self::assertCount(1, $crawler->filterXPath('//*[@data-testid="vault-health-weak"]'));
        self::assertCount(1, $generated);
        self::assertSame('scope:' . self::SCOPE, $generated[0]->getOwnerKey());
        self::assertStringNotContainsString($marker, serialize($generated[0]));

        // Alice sees the three: the two twins are one reuse group.
        $client->loginUser($alice);
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/health');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filterXPath('//*[@data-testid="vault-health-reused"]'));
        self::assertStringNotContainsString($marker, (string) $client->getResponse()->getContent());
    }

    public function testHealthAccessChecksAndSizeLimit(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $carol  = $this->createUser('carol@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, health: true, create: true));
        $this->member($carol, new VaultScopeAccess(VaultPermission::Admin));

        // A member without `health`, a non-member and a bad scope key: 404 (deny_status of this kernel).
        $client->loginUser($carol);
        foreach (['/tools/vault/scopes/' . self::SCOPE . '/health', '/tools/vault/scopes/org-2/health', '/tools/vault/scopes/Org-1/health'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $url);
        }

        // A host veto wins over the resolver.
        $client->loginUser($alice);
        ScopesKernelListeners::add(VaultEvents::HEALTH_ACCESS_CHECK, static function (VaultHealthAccessCheckEvent $event): void {
            $event->deny();
        });
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/health');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        ScopesKernelListeners::reset();

        // Empty scope: the empty state.
        $crawler = $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/health');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('0 checked', $crawler->filterXPath('//*[@data-testid="vault-health-summary"]')->text());

        // max_items is 3 on this kernel: a fourth login item refuses the report.
        foreach (['a', 'b', 'c', 'd'] as $title) {
            $this->scopeItem($alice, $title, 'Str0ng-Enough-Passw0rd!');
        }
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE . '/health');
        self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testPolicyIsEnforcedOnSaveWithoutEchoingTheValue(): void
    {
        $client = $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $this->member($alice, new VaultScopeAccess(VaultPermission::Admin, create: true));
        $client->loginUser($alice);
        $weak = 'MARK' . bin2hex(random_bytes(2));

        foreach (['/tools/vault/items/new/login', '/tools/vault/scopes/' . self::SCOPE . '/items/new/login'] as $url) {
            $crawler = $client->request('GET', $url);
            $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
                'vault_item_form[title]'    => 'Weak',
                'vault_item_form[password]' => $weak,
            ]));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $url);
            $html = (string) $client->getResponse()->getContent();
            self::assertStringContainsString('does not meet the password policy', $html);
            self::assertStringNotContainsString($weak, $html);
        }

        // A compliant password is saved; editing with the password left empty keeps it without re-validation.
        $crawler = $client->request('GET', '/tools/vault/items/new/login');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form([
            'vault_item_form[title]'    => 'Strong',
            'vault_item_form[password]' => 'Str0ng-Enough-Passw0rd!',
        ]));
        self::assertResponseRedirects();
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM vault_items WHERE title = ?', ['Strong']);
        self::assertIsString($id);
        $crawler = $client->request('GET', '/tools/vault/items/' . $id . '/edit');
        $client->submit($crawler->filterXPath('//form[.//input[@name="vault_item_form[title]"]]')->form(['vault_item_form[title]' => 'Strong 2']));
        self::assertResponseRedirects();
    }

    private function scopeItem(User $creator, string $title, string $password): VaultItem
    {
        return self::getContainer()->get(VaultItemCreator::class)->createInScope($this->managed($creator), VaultItemType::Login, $title, self::SCOPE, ['username' => 'u', 'password' => $password]);
    }

    private function member(User $user, VaultScopeAccess $access): void
    {
        TestScopeAccessResolver::$members[(string) $user->getId()][self::SCOPE] = $access;
    }
}
