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

    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function isHandled(): bool
    {
        return $this->result instanceof VaultBrowserExtensionAuthResult;
    }

    public function getResult(): ?VaultBrowserExtensionAuthResult
    {
        return $this->result;
    }

    public function setResult(VaultBrowserExtensionAuthResult $result): void
    {
        $this->result = $result;
    }
}
