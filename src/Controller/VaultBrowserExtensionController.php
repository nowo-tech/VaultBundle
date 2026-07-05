<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionLoginRateLimiter;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Service\VaultBrowserExtensionLoginResolver;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;

/**
 * Browser extension API (Bearer token auth, CORS). Session CSRF tokens do not apply here.
 */
final readonly class VaultBrowserExtensionController
{
    public function __construct(
        private VaultBrowserExtensionAuthService $authService,
        private VaultBrowserExtensionLoginResolver $loginResolver,
        private VaultBrowserExtensionResponseFactory $responseFactory,
        private VaultBrowserExtensionLoginRateLimiter $loginRateLimiter,
    ) {
    }

    public function login(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        /** @var mixed $payload */
        $payload  = json_decode($request->getContent(), true);
        $username = is_array($payload) ? (string) ($payload['username'] ?? '') : '';
        $password = is_array($payload) ? (string) ($payload['password'] ?? '') : '';

        if ($username === '' || $password === '') {
            return $this->responseFactory->json(['error' => 'username and password are required.'], Response::HTTP_BAD_REQUEST, $request);
        }

        $clientIp = (string) $request->getClientIp();
        if ($this->loginRateLimiter->isLimited($clientIp, $username)) {
            return $this->responseFactory->json(['error' => 'Too many login attempts. Try again later.'], Response::HTTP_TOO_MANY_REQUESTS, $request);
        }

        $result = $this->authService->login($username, $password);
        if ($result === null) {
            $this->loginRateLimiter->registerFailedAttempt($clientIp, $username);

            return $this->responseFactory->json(['error' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $this->loginRateLimiter->reset($clientIp, $username);

        return $this->responseFactory->json($result, Response::HTTP_OK, $request);
    }

    public function logins(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $domain = (string) $request->query->get('domain', '');
        if ($domain === '') {
            return $this->responseFactory->json(['error' => 'domain query parameter is required.'], Response::HTTP_BAD_REQUEST, $request);
        }

        $matches = $this->loginResolver->resolveForDomain($user, $domain);

        return $this->responseFactory->json([
            'domain' => $domain,
            'logins' => array_map(static fn (\Nowo\VaultBundle\Dto\VaultBrowserExtensionLoginMatch $match): array => $match->toArray(), $matches),
        ], Response::HTTP_OK, $request);
    }

    public function logout(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $token = $this->extractBearerToken($request);
        if ($token !== null) {
            $this->authService->logout($token);
        }

        return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
    }

    public function me(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->responseFactory->empty(Response::HTTP_NO_CONTENT, $request);
        }

        $user = $this->resolveBearerUser($request);
        if (!$user instanceof UserInterface) {
            return $this->responseFactory->json(['error' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        return $this->responseFactory->json([
            'userId'     => UserIdResolver::getId($user),
            'identifier' => $user->getUserIdentifier(),
        ], Response::HTTP_OK, $request);
    }

    private function resolveBearerUser(Request $request): ?UserInterface
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return null;
        }

        return $this->authService->resolveUser($token);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
