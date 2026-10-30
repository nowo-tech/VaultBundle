<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the manage Web UI is enabled without CSRF protection (REQ-SEC-005, cross-bundle pattern P2).
 *
 * The manage UI validates its row / toolbar actions (trash, restore, purge, revoke, folder and tag actions, password
 * generator, runtime config) with isCsrfTokenValid() and relies on Symfony's form CSRF extension for the item, share
 * and folder forms. A host that disables `framework.form.csrf_protection` (or has no CSRF token manager) would
 * silently turn the form checks off, so the container refuses to compile instead. The browser-extension API (Bearer
 * tokens) is not affected: set `nowo_vault.web_ui.enabled: false` to run it alone.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /**
     * Throws when the manage UI is enabled and the token manager or the form CSRF extension is missing or disabled.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_vault.web_ui.enabled') || $container->getParameter('nowo_vault.web_ui.enabled') !== true) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('VaultBundle: the manage Web UI (nowo_vault.web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (composer require symfony/security-csrf symfony/form), or set nowo_vault.web_ui.enabled: false.');
        }
    }
}
