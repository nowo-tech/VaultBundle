<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\E2E;

use Nowo\VaultBundle\Controller\VaultManageController;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\Tests\App\Kernel as AppKernel;
use Nowo\VaultBundle\Tests\E2E\Support\VaultWebTestTrait;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_key_exists;

/**
 * Guard branches of VaultManageController that the default kernel wiring cannot reach over HTTP: a denying access
 * checker, allow_unauthenticated without a user, config storage without admin role and a custom item repository.
 * The controller is rebuilt from the container's own arguments with one override each.
 */
final class VaultControllerGuardsTest extends WebTestCase
{
    use VaultWebTestTrait;

    protected static function getKernelClass(): string
    {
        return AppKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public function testDenyingAccessCheckerBlocksEveryFeature(): void
    {
        $this->bootClient();
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->method('canList')->willReturn(false);
        $checker->method('canCreate')->willReturn(false);
        $controller = $this->controller(['accessChecker' => $checker]);
        $this->pushRequest($this->createUser('alice@example.com'));

        $this->expectException(AccessDeniedException::class);
        $controller->home();
    }

    public function testAllowUnauthenticatedSkipsRoleChecksButNotItemAccess(): void
    {
        $this->bootClient();
        $alice   = $this->createUser('alice@example.com');
        $item    = $this->createItem($alice, 'Item');
        $folder  = $this->createFolder($alice, 'Folder');
        $checker = $this->createMock(VaultAccessCheckerInterface::class);
        $checker->expects(self::never())->method('canList');
        $controller = $this->controller(['accessChecker' => $checker, 'allowUnauthenticated' => true]);
        $request    = $this->pushRequest(null);

        self::assertSame(200, $controller->home()->getStatusCode());

        try {
            $controller->viewItem($item->getId());
            self::fail('Anonymous item view must be denied.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $controller->shareFolder($request, $folder->getId());
    }

    public function testRuntimeConfigLinkNeedsAnAdminRole(): void
    {
        $this->bootClient();
        $controller = $this->controller(['configStorage' => ['enabled' => true, 'cache_pool' => 'cache.app']]);
        $this->pushRequest($this->createUser('alice@example.com'));

        $response = $controller->home();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('runtime-config', (string) $response->getContent());
    }

    public function testFolderCountsFallBackToOneCountPerFolderForCustomRepositories(): void
    {
        $this->bootClient();
        $alice  = $this->createUser('alice@example.com');
        $folder = $this->createFolder($alice, 'Work');
        $repo   = $this->createMock(VaultItemRepositoryInterface::class);
        $repo->expects(self::once())->method('countActiveByFolder')->with($folder->getId())->willReturn(7);
        $controller = $this->controller(['itemRepository' => $repo]);
        $request    = $this->pushRequest($alice);

        $response = $controller->items($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Work', (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function controller(array $overrides): VaultManageController
    {
        /** @var VaultManageController $real */
        $real = self::getContainer()->get(VaultManageController::class);
        $ref  = new ReflectionClass($real);
        $args = [];
        foreach ($ref->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name        = $parameter->getName();
            $args[$name] = array_key_exists($name, $overrides) ? $overrides[$name] : $ref->getProperty($name)->getValue($real);
        }

        $controller = new VaultManageController(...$args);
        $controller->setContainer((new ReflectionProperty(AbstractController::class, 'container'))->getValue($real));

        return $controller;
    }

    private function pushRequest(?User $user): Request
    {
        $request = Request::create('/tools/vault/items');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        if ($user instanceof User) {
            self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->managed($user), 'main', $user->getRoles()));
        }

        return $request;
    }
}
