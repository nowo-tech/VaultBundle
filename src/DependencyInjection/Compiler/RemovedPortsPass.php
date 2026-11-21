<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function implode;
use function sprintf;
use function str_contains;

/**
 * Fails the build when the application still wires one of the six host ports removed in 2.0 (deprecated since 1.19,
 * REQ-ARCH-001 rule 3). A service registered under, aliased to or decorating a removed id would otherwise be ignored
 * without a word: the vault would ask the built-in instead of the application's lookup, and a dropped decorator of an
 * access port could widen access. The message names the service(s) and the 1.19 interface to implement instead.
 *
 * The ids are strings: the interfaces no longer exist. A class of the application that still implements one of them
 * cannot be loaded: when it is registered by resource discovery (e.g. with `#[AsAlias]` or `#[AsDecorator]`), Symfony
 * keeps its definition with only a loading error and later removes it as unused, without a word. The pass fails on
 * such a definition as well. A class that still implements a removed interface and is named by the configuration
 * (`security.access_checker`, …) is refused by {@see VaultPortsPass}.
 *
 * Runs first, before optimization (application definitions loaded, decorators not applied yet, nothing removed yet).
 *
 * @internal
 */
final class RemovedPortsPass implements CompilerPassInterface
{
    /** Removed in 2.0: the user lookup port (replaced by VaultUserDirectoryInterface). */
    public const USER_LOCATOR = 'Nowo\\VaultBundle\\Support\\VaultUserLocatorInterface';

    /**
     * Removed id => the 1.19 interface that replaces it (all deprecated since 1.19.0).
     *
     * @var array<string, string>
     */
    public const REMOVED_PORTS = [
        'Nowo\\VaultBundle\\Security\\VaultAccessCheckerInterface'           => VaultAccessPolicyInterface::class,
        'Nowo\\VaultBundle\\Security\\VaultTeamMembershipResolverInterface'  => VaultTeamMembershipProviderInterface::class,
        'Nowo\\VaultBundle\\Security\\VaultScopeAccessResolverInterface'     => VaultScopeAccessProviderInterface::class,
        'Nowo\\VaultBundle\\Security\\VaultReauthenticationCheckerInterface' => VaultReauthenticationProviderInterface::class,
        self::USER_LOCATOR                                                   => VaultUserDirectoryInterface::class,
        'Nowo\\VaultBundle\\Service\\VaultHealthReporterInterface'           => VaultHealthReportProviderInterface::class,
    ];

    /**
     * @throws LogicException when a removed port is still registered, aliased, decorated or implemented
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED_PORTS as $port => $twin) {
            $services = [];
            if ($container->hasAlias($port)) {
                $services[] = (string) $container->getAlias($port);
            } elseif ($container->hasDefinition($port)) {
                $services[] = $port;
            }
            foreach ($container->getDefinitions() as $id => $definition) {
                if (($definition->getDecoratedService()[0] ?? null) === $port || $this->failsToLoadBecauseOf($definition, $port)) {
                    $services[] = $id;
                }
            }

            if ($services !== []) {
                throw new LogicException(sprintf('"%s" was removed in 2.0 (deprecated since 1.19), but the application still registers, aliases, decorates or implements it: "%s". Implement "%s" instead (and alias, configure or decorate that interface); see UPGRADING.md "To 2.0.0".', $port, implode('", "', $services), $twin));
            }
        }
    }

    /**
     * Whether the definition carries a loading error that names the removed port (its class still implements it).
     */
    private function failsToLoadBecauseOf(Definition $definition, string $port): bool
    {
        foreach ($definition->getErrors() as $error) {
            if (str_contains($error, $port)) {
                return true;
            }
        }

        return false;
    }
}
