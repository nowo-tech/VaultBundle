<?php

declare(strict_types=1);

namespace App\VaultExamples\AccessControl;

use Nowo\VaultBundle\BrowserExtension\VaultBrowserExtensionAuthResult;
use Nowo\VaultBundle\Event\VaultBrowserExtensionAuthEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Example: custom browser-extension login (LDAP, 2FA, etc.).
 */
#[AsEventListener(event: VaultEvents::BROWSER_EXTENSION_AUTH)]
final class VaultBrowserExtensionAuthListener
{
    public function __invoke(VaultBrowserExtensionAuthEvent $event): void
    {
        // if ($this->ldap->authenticate($event->getUsername(), $event->getPassword())) {
        //     $event->setResult(VaultBrowserExtensionAuthResult::success($user));
        // }
        // $event->setResult(VaultBrowserExtensionAuthResult::failure('Invalid credentials.'));
    }
}
