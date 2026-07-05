<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\BrowserExtension;

/**
 * Authenticates browser-extension login requests (username + password).
 *
 * Replace this service or listen to VaultEvents::BROWSER_EXTENSION_AUTH for custom flows.
 */
interface VaultBrowserExtensionAuthenticatorInterface
{
    public function authenticate(string $username, string $password): VaultBrowserExtensionAuthResult;
}
