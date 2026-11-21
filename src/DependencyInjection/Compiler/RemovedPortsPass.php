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
use function is_string;
use function sprintf;
use function str_contains;
use function trigger_deprecation;

/**
 * The six host ports removed in 2.0 (deprecated since 1.19, REQ-ARCH-001 rule 3).
 *
 * - **Accepted with a deprecation (until 3.0):** the application aliases a removed id, or registers a service under it
 *   (the documented 1.17 way to replace the user lookup, e.g. `Nowo\VaultBundle\Support\VaultUserLocatorInterface:
 *   '@App\Directory'`), and the class behind it implements the framework-free twin. The twin is aliased to that service
 *   (as 1.21 asked it), unless the application aliases the twin to the same service already; if it aliases the twin to
 *   another service, the build fails (keep only one).
 * - **Refused (the build fails):** the class behind the removed id does not implement the twin (or cannot be known
 *   before compilation); a decorator of a removed id; an application class that still implements a removed interface.
 *   Such a class cannot be loaded: when it is registered by resource discovery (e.g. with `#[AsAlias]` or
 *   `#[AsDecorator]`), Symfony keeps its definition with only a loading error and would later remove it as unused,
 *   without a word (a dropped access decorator would widen access). A class that still implements a removed interface
 *   and is named by the configuration (`security.access_checker`, …) is refused by {@see VaultPortsPass}.
 *
 * The ids are strings: the interfaces no longer exist. Runs first, before optimization (application definitions
 * loaded, decorators not applied yet, nothing removed yet).
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

    private const PACKAGE = 'nowo-tech/vault-bundle';

    /**
     * @throws LogicException when a removed port is wired in a way 2.x cannot honour (see the class comment)
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::REMOVED_PORTS as $port => $twin) {
            $problems = [];
            $target   = null;
            if ($container->hasAlias($port)) {
                $target = (string) $container->getAlias($port);
            } elseif ($container->hasDefinition($port)) {
                $target = $port;
            }
            if ($target !== null) {
                $problem = $this->acceptAsTwin($container, $port, $twin, $target);
                if ($problem !== null) {
                    $problems[] = $problem;
                }
            }
            foreach ($container->getDefinitions() as $id => $definition) {
                if (($definition->getDecoratedService()[0] ?? null) === $port) {
                    $problems[] = sprintf('"%s" decorates it: decorate "%s" instead', $id, $twin);
                } elseif ($this->failsToLoadBecauseOf($definition, $port)) {
                    $problems[] = sprintf('the class of "%s" still implements it and cannot be loaded: implement only "%s"', $id, $twin);
                }
            }

            if ($problems !== []) {
                throw new LogicException(sprintf('"%s" was removed in 2.0 (deprecated since 1.19), but the application still uses it: %s. See UPGRADING.md "To 2.0.0".', $port, implode('; ', $problems)));
            }
        }
    }

    /**
     * An alias of, or a service under, a removed id whose class implements the twin: the twin is aliased to it (a 2.x
     * deprecation). Returns why it cannot be accepted, or null.
     */
    private function acceptAsTwin(ContainerBuilder $container, string $port, string $twin, string $target): ?string
    {
        $service = $this->resolve($container, $target);
        $class   = $this->classOf($container, $service);
        if ($class === null || $container->getReflectionClass($class, false)?->implementsInterface($twin) !== true) {
            return sprintf('"%s" is registered under it or aliased to it, and its class does not implement "%s": make it implement "%s" and alias that interface instead', $service, $twin, $twin);
        }

        if ($container->hasAlias($twin) || $container->hasDefinition($twin)) {
            $own = $this->resolve($container, $twin);
            if ($own !== $service) {
                return sprintf('it points at "%s" while "%s" points at "%s": keep only the alias of "%s"', $service, $twin, $own, $twin);
            }
        } else {
            $container->setAlias($twin, $service)->setPublic(false);
        }

        trigger_deprecation(self::PACKAGE, '2.0', 'The service id "%s" was removed in 2.0; the vault uses "%s" (which implements "%s") through that interface. Alias "%s" instead (the old id is accepted until 3.0).', $port, $service, $twin, $twin);

        return null;
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

    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }

    /**
     * The class of the definition behind the id, null when it cannot be known before compilation (no definition,
     * factory, synthetic).
     */
    private function classOf(ContainerBuilder $container, string $service): ?string
    {
        if (!$container->hasDefinition($service)) {
            return null;
        }

        $definition = $container->getDefinition($service);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $service);

        return is_string($class) ? $class : null;
    }
}
