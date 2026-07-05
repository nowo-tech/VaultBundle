<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Symfony\Component\HttpFoundation\Request;

use function is_array;
use function is_string;

trait VaultCsrfTrait
{
    private function denyUnlessValidCsrf(string $tokenId, Request $request): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $this->resolveCsrfTokenValue($request))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function resolveCsrfTokenValue(Request $request): string
    {
        $header = $request->headers->get('X-CSRF-Token');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $bodyToken = $request->request->getString('_token');
        if ($bodyToken !== '') {
            return $bodyToken;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded) && isset($decoded['_token']) && is_string($decoded['_token'])) {
            return $decoded['_token'];
        }

        return '';
    }
}
