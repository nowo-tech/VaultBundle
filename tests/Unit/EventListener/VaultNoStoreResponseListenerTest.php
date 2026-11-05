<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\EventListener;

use Nowo\VaultBundle\EventListener\VaultNoStoreResponseListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class VaultNoStoreResponseListenerTest extends TestCase
{
    public function testBundleControllersGetNoStore(): void
    {
        $response = $this->dispatch('Nowo\\VaultBundle\\Controller\\VaultManageController::viewItem', new Response('', 200, ['Cache-Control' => 'public, max-age=600']));

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('no-cache', $response->headers->get('Pragma'));
    }

    public function testOtherControllersAreLeftAlone(): void
    {
        foreach (['App\\Controller\\HomeController::index', null, ['not', 'a', 'string']] as $controller) {
            $response = $this->dispatch($controller, new Response('', 200, ['Cache-Control' => 'public, max-age=600']));

            self::assertSame('max-age=600, public', $response->headers->get('Cache-Control'));
            self::assertFalse($response->headers->has('Pragma'));
        }
    }

    private function dispatch(mixed $controller, Response $response): Response
    {
        $request = new Request();
        if ($controller !== null) {
            $request->attributes->set('_controller', $controller);
        }

        $kernel = $this->createStub(HttpKernelInterface::class);
        (new VaultNoStoreResponseListener())(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }
}
