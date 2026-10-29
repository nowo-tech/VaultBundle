<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function in_array;
use function str_starts_with;

/**
 * Builds browser extension API responses with the configured CORS headers.
 */
final readonly class VaultBrowserExtensionResponseFactory
{
    /**
     * Creates the factory with the allowed CORS origins and the kernel environment.
     *
     * @param list<string> $allowedOrigins use '*' to allow any origin (development only)
     */
    public function __construct(
        private array $allowedOrigins,
        private string $kernelEnvironment = 'prod',
    ) {
    }

    /**
     * Returns a JSON response with CORS headers applied for the request origin.
     *
     * @param array<string, mixed>|list<mixed>|null $data
     */
    public function json(mixed $data, int $status, ?Request $request = null): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $this->applyCors($response, $request);

        return $response;
    }

    /**
     * Returns an empty-body response with CORS headers applied for the request origin.
     */
    public function empty(int $status, ?Request $request = null): Response
    {
        $response = new Response('', $status);
        $this->applyCors($response, $request);

        return $response;
    }

    private function applyCors(Response $response, ?Request $request): void
    {
        if (!$request instanceof Request) {
            return;
        }

        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '') {
            return;
        }

        if (!$this->isOriginAllowed($origin)) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        // Bearer-token API: no cookies are needed, so credentialed CORS is never allowed.
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Vary', 'Origin');
    }

    private function isOriginAllowed(string $origin): bool
    {
        if (($this->allowedOrigins === ['*'] || in_array('*', $this->allowedOrigins, true))
            && $this->kernelEnvironment !== 'prod'
        ) {
            return true;
        }

        if (in_array('*', $this->allowedOrigins, true)) {
            return false;
        }

        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }

        // Any extension origin only while no allow-list is configured.
        if ($this->allowedOrigins !== []) {
            return false;
        }

        return str_starts_with($origin, 'chrome-extension://')
            || str_starts_with($origin, 'moz-extension://');
    }
}
