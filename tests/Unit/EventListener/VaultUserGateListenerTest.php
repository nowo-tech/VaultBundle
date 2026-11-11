<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\EventListener;

use Nowo\VaultBundle\EventListener\VaultUserGateListener;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

final class VaultUserGateListenerTest extends TestCase
{
    private const VAULT = 'Nowo\\VaultBundle\\Controller\\VaultManageController::items';

    public function testLetsVaultUsersAndOtherControllersThrough(): void
    {
        $this->expectNotToPerformAssertions();

        $this->dispatch(self::VAULT, new TestUser());
        $this->dispatch(self::VAULT, null);
        $this->dispatch('App\\Controller\\AdminController::index', new OtherDomainUser('user-1', 'admin@example.com'));
        $this->dispatch(['not', 'a', 'string'], new OtherDomainUser('user-1', 'admin@example.com'));
        $this->listener(null)->onKernelController($this->event(self::VAULT));
    }

    public function testRefusesAUserOfAnotherIdentityDomain(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->dispatch(self::VAULT, new OtherDomainUser('user-1', 'admin@example.com'));
    }

    private function dispatch(mixed $controller, ?UserInterface $user): void
    {
        $storage = new TokenStorage();
        if ($user instanceof UserInterface) {
            $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        $this->listener($storage)->onKernelController($this->event($controller));
    }

    private function listener(?TokenStorage $storage): VaultUserGateListener
    {
        return new VaultUserGateListener(new DoctrineVaultUserReferenceResolver(TestUser::class, new VaultUserClassPolicy(TestUser::class)), $storage);
    }

    private function event(mixed $controller): ControllerEvent
    {
        $request = new Request();
        $request->attributes->set('_controller', $controller);

        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
