<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\Ports;

use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Service\VaultGrantService;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Tests\App\VariantKernel;
use Nowo\VaultBundle\Tests\App\Worker\NoopServicesResetter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function assert;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function str_contains;

/**
 * REQ-RUNTIME-002 (1.19.0): one kernel kept alive across many requests with the services resetter bypassed
 * (FrankenPHP worker mode, kernel reset false, no `kernel.reset`), the 1.19 ports and the private entity manager.
 * Users alternate on every request: each one sees only its own items and grants, a grant revoked between two requests
 * is never served from a stale identity map, the private manager is dropped at every request boundary, and memory
 * stays flat.
 */
final class WorkerKeptAliveKernelTest extends WebTestCase
{
    use VariantKernelSupport;

    /** Requests per run (alternating users), after the warm-up. */
    private const REQUESTS = 600;

    /** Memory margin over the run, in bytes (the first requests warm Twig, the router and the metadata). */
    private const MEMORY_MARGIN = 3 * 1024 * 1024;

    protected static function getKernelClass(): string
    {
        return VariantKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function variants(): iterable
    {
        yield 'relation mapping, 1.19 ports, private manager' => ['private_relation_new_worker'];
        yield 'DDD host: id + XML mapping, private manager' => ['private_idxml_new_worker'];
    }

    #[Test]
    #[DataProvider('variants')]
    public function alternatingUsersNeverSeeEachOthersRowsAndMemoryStaysFlat(string $variant): void
    {
        self::ensureKernelShutdown();
        $client = self::createClient(['environment' => $variant, 'debug' => false]);
        $client->disableReboot();
        $container = self::getContainer();
        self::resetSchema($container);
        $users = self::seedUsers($container, str_contains($variant, '_id'));
        $ids   = self::seedVault($container, $users);
        $vault = $container->get(VaultEntityManager::class);
        assert($vault instanceof VaultEntityManager);
        NoopServicesResetter::$calls = 0;

        $expect = [
            'alice' => ['Title-P' => true, 'Title-PF' => true],
            'bob'   => ['Title-P' => false, 'Title-PF' => false],
        ];
        $start = 0;
        for ($i = 0; $i < self::REQUESTS + 20; ++$i) {
            if ($i === 20) {
                gc_collect_cycles();
                $start = memory_get_usage();
            }
            $name = $i % 2 === 0 ? 'alice' : 'bob';
            $client->loginUser($users[$name]);
            $client->request('GET', '/tools/vault/items');
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $content = (string) $client->getResponse()->getContent();
            foreach ($expect[$name] as $title => $visible) {
                self::assertSame($visible, str_contains($content, $title), sprintf('request %d as %s: %s', $i, $name, $title));
            }
            self::assertFalse($vault->isCreated(), 'the private manager is dropped at the end of every request');

            // bob's shared view and the item page follow the grant, whatever the previous request loaded.
            if ($i % 50 === 1) {
                $client->request('GET', '/tools/vault/items/' . $ids['personal']);
                self::assertSame($this->bobHasAGrant($container, $ids['personal'], (string) UserIdResolver::getId($users['bob'])) ? 200 : 403, $client->getResponse()->getStatusCode(), 'request ' . $i);
            }
            if ($i === 301) {
                $this->revokeBob($container, $ids['personal'], (string) UserIdResolver::getId($users['bob']));
            }
        }
        gc_collect_cycles();

        self::assertGreaterThan(0, NoopServicesResetter::$calls, 'the kernel calls the (no-op) resetter: nothing tagged kernel.reset runs');
        self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $start, sprintf('memory grew by %d bytes over %d requests', memory_get_usage() - $start, self::REQUESTS));
        self::assertFalse($this->bobHasAGrant($container, $ids['personal'], (string) UserIdResolver::getId($users['bob'])));
    }

    private function bobHasAGrant(ContainerInterface $container, string $itemId, string $bobId): bool
    {
        $grants = $container->get(VaultGrantRepositoryInterface::class);
        assert($grants instanceof VaultGrantRepositoryInterface);

        return $grants->findOne(VaultResourceType::Item, $itemId, GranteeType::User, $bobId) instanceof VaultGrant;
    }

    private function revokeBob(ContainerInterface $container, string $itemId, string $bobId): void
    {
        $grants  = $container->get(VaultGrantRepositoryInterface::class);
        $service = $container->get(VaultGrantService::class);
        assert($grants instanceof VaultGrantRepositoryInterface && $service instanceof VaultGrantService);
        $grant = $grants->findOne(VaultResourceType::Item, $itemId, GranteeType::User, $bobId);
        assert($grant instanceof VaultGrant);
        $service->revoke($grant);
    }

    #[Test]
    public function theNoopResetterReplacesTheServicesResetter(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'private_relation_new_worker']);
        self::assertInstanceOf(NoopServicesResetter::class, self::getContainer()->get('services_resetter'));
    }
}
