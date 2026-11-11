<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Service\VaultItemCreator;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Worker\DbalScopeAccessResolver;
use Nowo\VaultBundle\Tests\App\Worker\NoopServicesResetter;
use Nowo\VaultBundle\Tests\App\WorkerScopesKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * 1.17.1 (REQ-RUNTIME-002): one kernel kept alive across requests with kernel reset false (no services_resetter, see
 * {@see WorkerScopesKernel}). A scope membership revoked in the database between two requests must be refused on the
 * very next request, and two users alternating on the same worker never see each other's standing. Before 1.17.1 the
 * per-request scope memo was cleared only by kernel.reset, so the revoked member kept access in that worker.
 *
 * Worker test: 100 rounds × 2 users (400 requests) on one kernel, the second user's membership toggled by SQL every
 * round; memory after a 10-round warm-up may grow by at most 4 MiB.
 */
final class VaultScopeWorkerRevocationTest extends WebTestCase
{
    use VaultWebTestTrait;

    private const SCOPE = 'org-1';

    private const ROUNDS = 100;

    private const WARM_UP_ROUNDS = 10;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    protected static function getKernelClass(): string
    {
        return WorkerScopesKernel::class;
    }

    protected function setUp(): void
    {
        DbalScopeAccessResolver::$calls = 0;
        NoopServicesResetter::$calls    = 0;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testMembershipRevokedInTheDatabaseIsRefusedOnTheNextRequest(): void
    {
        [$client, $alice] = $this->fixture();

        $client->loginUser($alice);
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Stripe', (string) $client->getResponse()->getContent());

        // Revoked behind the bundle's back (another request, worker or admin tool), with plain SQL.
        $this->connection()->executeStatement('DELETE FROM ' . DbalScopeAccessResolver::TABLE . ' WHERE user_id = ?', [(string) $alice->getId()]);

        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'A revoked member must be refused on the next request of the same worker.');
        self::assertStringNotContainsString('Stripe', (string) $client->getResponse()->getContent());
        self::assertGreaterThan(0, NoopServicesResetter::$calls, 'The kernel asked for a reset, and nothing was reset.');

        // Granted again: served again on the next request.
        $this->grant($alice);
        $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
        self::assertResponseIsSuccessful();
    }

    public function testTwoUsersAlternatingOnOneWorkerNeverShareTheirStanding(): void
    {
        [$client, $alice, $bob] = $this->fixture();
        $baseline               = 0;

        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $bobIsMember = $round % 2 === 0;
            if ($bobIsMember) {
                $this->grant($bob);
            } else {
                $this->connection()->executeStatement('DELETE FROM ' . DbalScopeAccessResolver::TABLE . ' WHERE user_id = ?', [(string) $bob->getId()]);
            }

            foreach ([[$alice, true], [$bob, $bobIsMember], [$alice, true], [$bob, $bobIsMember]] as [$user, $member]) {
                $client->loginUser($user);
                $client->request('GET', '/tools/vault/scopes/' . self::SCOPE);
                $status = $client->getResponse()->getStatusCode();
                self::assertSame($member ? Response::HTTP_OK : Response::HTTP_NOT_FOUND, $status, 'Round ' . $round . ', ' . $user->getUserIdentifier());
            }

            if ($round === self::WARM_UP_ROUNDS) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'Memory must stay flat over many requests on one worker.');
    }

    /**
     * Fresh schema, the membership table, Alice (member, may create) with one scope item, and Bob (no membership).
     *
     * @return array{KernelBrowser, User, User}
     */
    private function fixture(): array
    {
        // debug off: 400 requests in one kernel without the debug logger's per-event lines.
        $client = self::createClient(['debug' => false]);
        // One kernel for every request: never shut down and rebooted between requests (a real worker).
        $client->disableReboot();
        $em   = $this->em();
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        DbalScopeAccessResolver::createTable($this->connection());
        $alice = $this->createUser('alice@example.com');
        $bob   = $this->createUser('bob@example.com');
        $this->grant($alice);

        /** @var VaultItemCreator $creator */
        $creator = self::getContainer()->get(VaultItemCreator::class);
        $creator->createInScope($this->managed($alice), VaultItemType::Login, 'Stripe', self::SCOPE, []);

        return [$client, $alice, $bob];
    }

    private function grant(User $user): void
    {
        $this->connection()->executeStatement(
            'INSERT OR REPLACE INTO ' . DbalScopeAccessResolver::TABLE . ' (user_id, scope, permission, can_create) VALUES (?, ?, ?, 1)',
            [(string) $user->getId(), self::SCOPE, 'admin'],
        );
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        return $connection;
    }
}
