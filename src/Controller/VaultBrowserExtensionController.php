<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Controller;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionLoginRateLimiter;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionResponseFactory;
use Nowo\VaultBundle\Dto\VaultBrowserExtensionLoginMatch;
use Nowo\VaultBundle\Routing\VaultRouteLoader;
use Nowo\VaultBundle\Service\VaultBrowserExtensionAuthService;
use Nowo\VaultBundle\Service\VaultBrowserExtensionLoginResolver;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;

/**
 * Browser extension API (Bearer token auth, CORS). Session CSRF tokens do not apply here.
 */
final readonly class VaultBrowserExtensionController
{
    /**
     * Stores the auth service, login resolver, response factory and login rate limiter.
     */
    public function __construct(
        private VaultBrowserExtensionAuthService $authService,
        private VaultBrowserExtensionLoginResolver $loginResolver,
        private VaultBrowserExtensionResponseFactory $responseFactory,
        private VaultBrowserExtensionLoginRateLimiter $loginRateLimiter,
    ) {
    }

    /**
     * Exchanges username/password for a Bearer token (rate limited, CORS preflight aware).
     */
    #[Route(path: '/api/vault/extension/login', name: 'nowo_vault_extension_login', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'login'], methods: ['POST', 'OPTIONS'])]
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
            // @igor-ignore - HTTP handler delegates to services; no controller worker state.
            $this->loginRateLimiter->registerFailedAttempt($clientIp, $username);

            return $this->responseFactory->json(['error' => 'Invalid credentials.'], Response::HTTP_UNAUTHORIZED, $request);
        }

        $this->loginRateLimiter->reset($clientIp, $username);

        return $this->responseFactory->json($result, Response::HTTP_OK, $request);
    }

    /**
     * Returns the login items matching the requested domain for the Bearer token's user.
     */
    #[Route(path: '/api/vault/extension/logins', name: 'nowo_vault_extension_logins', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'logins'], methods: ['GET', 'OPTIONS'])]
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

        // Page scheme sent by extension >= 1.11 (older clients: https semantics, as before).
        $scheme  = $request->query->get('scheme') === 'http' ? 'http' : 'https';
        $matches = $this->loginResolver->resolveForDomain($user, $domain, $scheme);

        return $this->responseFactory->json([
            'domain' => $domain,
            'logins' => array_map(static fn (VaultBrowserExtensionLoginMatch $match): array => $match->toArray(), $matches),
        ], Response::HTTP_OK, $request);
    }

    /**
     * Revokes the presented Bearer token.
     */
    #[Route(path: '/api/vault/extension/logout', name: 'nowo_vault_extension_logout', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'logout'], methods: ['POST', 'OPTIONS'])]
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

    /**
     * Returns the identity of the Bearer token's user.
     */
    #[Route(path: '/api/vault/extension/me', name: 'nowo_vault_extension_me', options: [VaultRouteLoader::ROUTE_KEY_OPTION => 'me'], methods: ['GET', 'OPTIONS'])]
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
