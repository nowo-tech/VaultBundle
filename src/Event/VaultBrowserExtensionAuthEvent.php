<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Event;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before the default authenticator runs.
 * Listeners may call setResult() to handle auth entirely (LDAP, 2FA, etc.).
 */
final class VaultBrowserExtensionAuthEvent extends Event
{
    private ?VaultBrowserExtensionAuthResult $result = null;

    /**
     * Creates the event for a browser extension login attempt.
     */
    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    /**
     * Returns the submitted username.
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Returns the submitted password (never log it).
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Whether a listener already produced an authentication result.
     */
    public function isHandled(): bool
    {
        return $this->result instanceof VaultBrowserExtensionAuthResult;
    }

    /**
     * Returns the result set by a listener, or null.
     */
    public function getResult(): ?VaultBrowserExtensionAuthResult
    {
        return $this->result;
    }

    /**
     * Lets a listener provide the authentication result.
     */
    public function setResult(VaultBrowserExtensionAuthResult $result): void
    {
        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->result = $result;
    }
}
