<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Controller;

use Nowo\VaultBundle\Controller\VaultCsrfTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

use const JSON_THROW_ON_ERROR;

final class VaultCsrfTraitTest extends TestCase
{
    public function testResolveCsrfTokenFromHeaderAndJsonBody(): void
    {
        $controller = new class extends AbstractController {
            use VaultCsrfTrait;

            public function extract(Request $request): string
            {
                $method = new ReflectionMethod($this, 'resolveCsrfTokenValue');

                return $method->invoke($this, $request);
            }
        };

        $headerRequest = Request::create('/', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'header-token']);
        self::assertSame('header-token', $controller->extract($headerRequest));

        $jsonRequest = Request::create(
            '/',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['_token' => 'json-token'], JSON_THROW_ON_ERROR),
        );
        self::assertSame('json-token', $controller->extract($jsonRequest));

        $formRequest = Request::create('/', 'POST', ['_token' => 'form-token']);
        self::assertSame('form-token', $controller->extract($formRequest));
    }
}
