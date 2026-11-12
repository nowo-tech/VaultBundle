<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateInterval;
use DateTimeImmutable;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthenticatorInterface;
use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Event\VaultBrowserExtensionAuthEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Repository\VaultExtensionTokenRepositoryInterface;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function base64_encode;
use function count;
use function hash;
use function random_bytes;
use function rtrim;
use function strtr;

use const DATE_ATOM;

/**
 * Issues, resolves and revokes browser extension Bearer tokens.
 */
final readonly class VaultBrowserExtensionAuthService
{
    private const LAST_USED_TOUCH_INTERVAL_SECONDS = 300;

    /**
     * Stores the authenticator, token repository, dispatcher, TTL and the optional access checker, user checker, clock and logger.
     */
    public function __construct(
        private VaultBrowserExtensionAuthenticatorInterface $authenticator,
        private VaultExtensionTokenRepositoryInterface $tokenRepository,
        private EventDispatcherInterface $eventDispatcher,
        private int $tokenTtlSeconds,
        private ?VaultAccessCheckerInterface $accessChecker = null,
        private ?UserCheckerInterface $userChecker = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        private ?VaultUserReferenceResolverInterface $users = null,
        private ?VaultUserLocatorInterface $userLocator = null,
        private ?VaultUserRefResolver $refs = null,
    ) {
    }

    /**
     * Authenticates the credentials and returns a new Bearer token, or null when rejected.
     *
     * @return array{token: string, expiresAt: string}|null
     */
    public function login(string $username, string $password): ?array
    {
        $authEvent = new VaultBrowserExtensionAuthEvent($username, $password);
        $this->eventDispatcher->dispatch($authEvent, VaultEvents::BROWSER_EXTENSION_AUTH);

        $result = $authEvent->isHandled()
            ? $authEvent->getResult()
            : $this->authenticator->authenticate($username, $password);

        if (!$result instanceof VaultBrowserExtensionAuthResult || !$result->isSuccess()) {
            // Never log the submitted username or password (REQ-OBS-001).
            $this->logger()->notice('Vault browser extension login rejected.', ['bundle' => 'nowo_vault', 'action' => 'extension_login', 'reason' => 'invalid_credentials']);

            return null;
        }

        $user = $result->getUser();
        $ref  = $result->getUserRef();
        if (!$user instanceof UserInterface && $ref instanceof VaultUserRef) {
            // 1.19.0 (NJ-1): a result by id alone is looked up again; its identity and roles come from the directory.
            $user = $this->refs?->resolve($ref);
        }
        // 1.17.0: a user the vault does not accept (another identity domain) never gets a token.
        if (!$user instanceof UserInterface || $this->users?->isAccepted($user) === false || !$this->isStillAllowed($user)) {
            $this->logger()->notice('Vault browser extension login rejected.', ['bundle' => 'nowo_vault', 'action' => 'extension_login', 'reason' => 'access_denied']);

            return null;
        }

        $this->logger()->info('Vault browser extension token issued.', ['bundle' => 'nowo_vault', 'action' => 'extension_login', 'user_id' => UserIdResolver::getId($user)]);

        return $this->issueToken($user);
    }

    /**
     * Returns the user of a valid Bearer token, revoking every token of a user who lost access.
     */
    public function resolveUser(string $plainToken): ?UserInterface
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if (!$entity instanceof VaultExtensionToken) {
            return null;
        }

        $user = $entity->getUser();
        if (!$user instanceof UserInterface) {
            // 1.17.0 id mapping: the token holds the user id; load the user through the locator port.
            $userId = $entity->getUserId();
            $user   = $userId !== null ? $this->userLocator?->find($userId) : null;
        }
        // LQ-V-I3: whatever the locator returns must still be a vault user.
        if (!$user instanceof UserInterface || $this->users?->isAccepted($user) === false) {
            return null;
        }

        // Tokens live for token_ttl: a user disabled, locked or stripped of the Vault roles after login loses access
        // on the next request, and every token of that user is revoked.
        if (!$this->isStillAllowed($user)) {
            $this->revokeAllFor($user);

            return null;
        }

        $this->touchIfStale($entity);

        return $user;
    }

    /**
     * Removes every extension token of the user (e.g. after a password change or when the account is disabled).
     *
     * @return int Number of revoked tokens
     */
    public function revokeAllFor(UserInterface $user): int
    {
        $tokens = $this->tokenRepository->findByUser($user);
        foreach ($tokens as $token) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->tokenRepository->remove($token);
        }

        $this->logger()->info('Vault browser extension tokens revoked.', ['bundle' => 'nowo_vault', 'action' => 'extension_revoke_all', 'user_id' => UserIdResolver::getId($user), 'count' => count($tokens)]);

        return count($tokens);
    }

    /**
     * Vault role gate ({@see VaultAccessCheckerInterface::canAccess()}) and the firewall user checker (disabled,
     * locked, expired accounts), both evaluated for the token's user.
     */
    private function isStillAllowed(UserInterface $user): bool
    {
        if ($this->accessChecker instanceof VaultAccessCheckerInterface && !$this->accessChecker->canAccess($user)) {
            return false;
        }

        try {
            $this->userChecker?->checkPreAuth($user);
            $this->userChecker?->checkPostAuth($user);
        } catch (AccountStatusException) {
            return false;
        }

        return true;
    }

    /**
     * Revokes the presented Bearer token when it is valid.
     */
    public function logout(string $plainToken): void
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if ($entity instanceof VaultExtensionToken) {
            // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
            $this->tokenRepository->remove($entity);
        }
    }

    /**
     * @return array{token: string, expiresAt: string}
     */
    private function issueToken(UserInterface $user): array
    {
        $plainToken = $this->generatePlainToken();
        $expiresAt  = $this->now()->add(new DateInterval('PT' . max(1, $this->tokenTtlSeconds) . 'S'));

        $this->tokenRepository->save(new VaultExtensionToken(
            self::hashToken($plainToken),
            $expiresAt,
            $user,
        ));

        return [
            'token'     => $plainToken,
            'expiresAt' => $expiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the SHA-256 hash stored for a plain Bearer token.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function generatePlainToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function touchIfStale(VaultExtensionToken $entity): void
    {
        $lastUsed = $entity->getLastUsedAt();
        $now      = $this->now();

        if ($lastUsed instanceof DateTimeImmutable && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $entity->touch($now);
        $this->tokenRepository->save($entity);
    }

    /**
     * Returns the current time from the injected clock (REQ-DI-001), or the system clock when none is wired.
     */
    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? new NativeClock())->now();
    }

    /**
     * Returns the injected logger (REQ-OBS-001), or a no-op logger when none is wired.
     */
    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
