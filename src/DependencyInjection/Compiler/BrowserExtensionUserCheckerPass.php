<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\BrowserExtension\DefaultVaultBrowserExtensionAuthenticator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_unshift;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Points the default browser-extension authenticator at the firewall user checker.
 *
 * SecurityBundle registers `security.user_checker.<firewall>` for every firewall with security
 * enabled (alias to the firewall `user_checker`, or the per-firewall chain extended by bundles such
 * as UserKit). The global UserCheckerInterface alias is the no-op InMemoryUserChecker (or missing
 * when any firewall sets a custom checker), so it cannot be relied on to reject disabled users.
 *
 * Only runs when `browser_extension.user_checker` was not set explicitly (the extension tags the
 * authenticator in that case).
 */
final class BrowserExtensionUserCheckerPass implements CompilerPassInterface
{
    public const TAG = 'nowo_vault.browser_extension.auto_user_checker';

    private const DEFAULT_FIREWALL = 'main';

    public function process(ContainerBuilder $container): void
    {
        $serviceId = DefaultVaultBrowserExtensionAuthenticator::class;
        if (!$container->hasDefinition($serviceId)) {
            return;
        }

        $definition = $container->getDefinition($serviceId);
        $tags       = $definition->getTag(self::TAG);
        if ($tags === []) {
            return;
        }
        $definition->clearTag(self::TAG);

        $firewall  = $tags[0]['firewall'] ?? null;
        $firewalls = $this->getFirewallNames($container);

        if (is_string($firewall) && $firewall !== '') {
            $checkerId = 'security.user_checker.' . $firewall;
            if (!$container->has($checkerId)) {
                throw new LogicException(sprintf('nowo_vault.browser_extension.firewall "%s" is not a firewall with security enabled (known firewalls: %s).', $firewall, $firewalls === [] ? 'none' : implode(', ', $firewalls)));
            }

            $definition->setArgument('$userChecker', new Reference($checkerId));

            return;
        }

        if (in_array(self::DEFAULT_FIREWALL, $firewalls, true)) {
            array_unshift($firewalls, self::DEFAULT_FIREWALL);
        }

        foreach ($firewalls as $name) {
            $checkerId = 'security.user_checker.' . $name;
            if ($container->has($checkerId)) {
                $definition->setArgument('$userChecker', new Reference($checkerId));

                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getFirewallNames(ContainerBuilder $container): array
    {
        if (!$container->hasParameter('security.firewalls')) {
            return [];
        }

        $names = $container->getParameter('security.firewalls');
        if (!is_array($names)) {
            return [];
        }

        $result = [];
        foreach ($names as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return $result;
    }
}
