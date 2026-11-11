<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\EventListener;

use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\EventListener\VaultRequestBoundaryListener;
use Nowo\VaultBundle\Security\RequestScopedScopeAccessCache;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\SodiumVaultPayloadCryptographerTestKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_column;
use function in_array;

/**
 * 1.17.1 (REQ-RUNTIME-002): the scope membership memo is cleared by the bundle itself at every boundary, not only by
 * kernel.reset, so a revoked membership stops granting access in a worker that never runs the services resetter.
 */
final class VaultRequestBoundaryListenerTest extends TestCase
{
    /** @var array<string, VaultScopeAccess> */
    private array $members = [];

    private int $resolves = 0;

    public function testMainRequestClearsTheMemoAndASubRequestKeepsIt(): void
    {
        [$cache, $listener] = $this->listener();
        $user               = new TestUser('2');

        $this->members['org-1'] = new VaultScopeAccess(VaultPermission::Read);
        self::assertInstanceOf(VaultScopeAccess::class, $cache->resolve($user, 'org-1'));

        // Membership revoked by another request: the sub-request still sees its main request's answer.
        $this->members = [];
        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::SUB_REQUEST));
        self::assertInstanceOf(VaultScopeAccess::class, $cache->resolve($user, 'org-1'));
        self::assertSame(1, $this->resolves);

        // The next main request re-asks the host resolver.
        $listener->onKernelRequest($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
        self::assertNull($cache->resolve($user, 'org-1'), 'A revoked membership is refused on the next request.');
        self::assertSame(2, $this->resolves);
    }

    public function testEveryOtherBoundaryClearsTheMemo(): void
    {
        [$cache, $listener] = $this->listener();
        $user               = new TestUser('2');

        $this->members['org-1'] = new VaultScopeAccess(VaultPermission::Read);
        self::assertInstanceOf(VaultScopeAccess::class, $cache->resolve($user, 'org-1'));
        $this->members = [];
        $listener->onBoundary();
        self::assertNull($cache->resolve($user, 'org-1'));
        self::assertSame(2, $this->resolves);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function boundaryEvents(): iterable
    {
        yield 'main request' => ['kernel.request', 'onKernelRequest'];
        yield 'terminate' => ['kernel.terminate', 'onBoundary'];
        yield 'console terminate' => ['console.terminate', 'onBoundary'];
        yield 'messenger handled' => [VaultRequestBoundaryListener::WORKER_MESSAGE_HANDLED, 'onBoundary'];
        yield 'messenger failed' => [VaultRequestBoundaryListener::WORKER_MESSAGE_FAILED, 'onBoundary'];
    }

    #[DataProvider('boundaryEvents')]
    public function testTheExtensionWiresEveryBoundary(string $event, string $method): void
    {
        foreach ([[], ['scopes' => ['enabled' => true, 'access_resolver' => 'app.scope_resolver']]] as $config) {
            $container = new ContainerBuilder();
            $container->setParameter('kernel.environment', 'test');
            $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);
            (new VaultExtension())->load([['user_class' => 'App\\Entity\\User', 'encryption_key' => SodiumVaultPayloadCryptographerTestKey::VALID] + $config], $container);

            $definition = $container->getDefinition(VaultRequestBoundaryListener::class);
            $tags       = $definition->getTag('kernel.event_listener');
            self::assertContains($event, array_column($tags, 'event'));
            foreach ($tags as $tag) {
                if ($tag['event'] === $event) {
                    self::assertSame($method, $tag['method']);
                    self::assertTrue(in_array($tag['priority'], [2048, -2048], true));
                }
            }
            self::assertTrue($container->getDefinition(RequestScopedScopeAccessCache::class)->hasTag('kernel.reset'), 'kernel.reset stays as a second line.');
        }
    }

    /**
     * @return array{RequestScopedScopeAccessCache, VaultRequestBoundaryListener}
     */
    private function listener(): array
    {
        $cache = new RequestScopedScopeAccessCache(new class($this) implements VaultScopeAccessResolverInterface {
            public function __construct(private readonly VaultRequestBoundaryListenerTest $test)
            {
            }

            public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
            {
                return $this->test->lookup($scope);
            }
        });

        return [$cache, new VaultRequestBoundaryListener($cache)];
    }

    /**
     * Host resolver stand-in: counts the calls and answers from the current membership map.
     */
    public function lookup(string $scope): ?VaultScopeAccess
    {
        ++$this->resolves;

        return $this->members[$scope] ?? null;
    }

    private function requestEvent(int $type): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), Request::create('/'), $type);
    }
}
