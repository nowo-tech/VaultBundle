<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Nowo\VaultBundle\Exception\VaultRevealRefusedException;
use Nowo\VaultBundle\Support\VaultSensitiveResponseHeaders;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Responses of the routes that return decrypted values (1.16.0): the sensitive headers and the mapping of a refused
 * reveal. For controllers extending AbstractController.
 */
trait VaultRevealResponsesTrait
{
    /**
     * Maps a refused reveal: not revealable => 404 (same answer as an unknown field), throttled => 429 + Retry-After,
     * audit failure => 503.
     */
    private function revealRefused(VaultRevealRefusedException $exception): JsonResponse
    {
        return match ($exception->getReason()) {
            VaultRevealRefusedException::THROTTLED    => $this->sensitive(new JsonResponse(['error' => 'Too many requests.'], Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $exception->getRetryAfter()])),
            VaultRevealRefusedException::AUDIT_FAILED => $this->sensitive(new JsonResponse(['error' => 'Service unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE)),
            default                                   => throw $this->createNotFoundException('Vault item not found.'),
        };
    }

    /**
     * Headers of a response that carries a decrypted value (contract §6.6); `no-store` comes from the bundle listener.
     *
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    private function sensitive(Response $response): Response
    {
        foreach (VaultSensitiveResponseHeaders::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
