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

final readonly class VaultBrowserExtensionAuthService
{
    private const LAST_USED_TOUCH_INTERVAL_SECONDS = 300;

    public function __construct(
        private VaultBrowserExtensionAuthenticatorInterface $authenticator,
        private VaultExtensionTokenRepositoryInterface $tokenRepository,
        private EventDispatcherInterface $eventDispatcher,
        private int $tokenTtlSeconds,
        private ?VaultAccessCheckerInterface $accessChecker = null,
        private ?UserCheckerInterface $userChecker = null,
    ) {
    }

    /**
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
            return null;
        }

        $user = $result->getUser();
        if (!$user instanceof UserInterface || !$this->isStillAllowed($user)) {
            return null;
        }

        return $this->issueToken($user);
    }

    public function resolveUser(string $plainToken): ?UserInterface
    {
        $entity = $this->tokenRepository->findValidByTokenHash(self::hashToken($plainToken));
        if (!$entity instanceof VaultExtensionToken) {
            return null;
        }

        $user = $entity->getUser();
        if (!$user instanceof UserInterface) {
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
        $expiresAt  = (new DateTimeImmutable())->add(new DateInterval('PT' . max(1, $this->tokenTtlSeconds) . 'S'));

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
        $now      = new DateTimeImmutable();

        if ($lastUsed instanceof DateTimeImmutable && ($now->getTimestamp() - $lastUsed->getTimestamp()) < self::LAST_USED_TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $entity->touch();
        $this->tokenRepository->save($entity);
    }
}
