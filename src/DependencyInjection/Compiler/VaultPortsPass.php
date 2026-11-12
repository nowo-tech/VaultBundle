<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\Port\VaultAccessCheckerGate;
use Nowo\VaultBundle\Port\VaultHealthReporterGate;
use Nowo\VaultBundle\Port\VaultPortMode;
use Nowo\VaultBundle\Port\VaultReauthenticationGate;
use Nowo\VaultBundle\Port\VaultScopeAccessGate;
use Nowo\VaultBundle\Port\VaultTeamMembershipGate;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationCheckerInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultHealthReporterInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function class_exists;
use function implode;
use function is_a;
use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * Wires the six host ports that have a framework-free 1.19 twin (REQ-ARCH-001 rule 3) behind the bundle's internal
 * gates (`nowo_vault.port.<name>`), which the vault services use instead of the ports themselves.
 *
 * | Port | 1.19 interface | Deprecated interface | Both asked |
 * |---|---|---|---|
 * | `access_checker` | {@see VaultAccessPolicyInterface} | {@see VaultAccessCheckerInterface} | both must allow |
 * | `team_membership` | {@see VaultTeamMembershipProviderInterface} | {@see VaultTeamMembershipResolverInterface} | team ids both return |
 * | `scope_access` | {@see VaultScopeAccessProviderInterface} | {@see VaultScopeAccessResolverInterface} | weaker standing |
 * | `reauthentication` | {@see VaultReauthenticationProviderInterface} | {@see VaultReauthenticationCheckerInterface} | never (build fails) |
 * | `user_locator` | {@see VaultUserDirectoryInterface} | {@see VaultUserLocatorInterface} | never (build fails) |
 * | `health_reporter` | {@see VaultHealthReportProviderInterface} | {@see VaultHealthReporterInterface} | never (build fails) |
 *
 * For each port, the service behind it is the one the configuration names (or the built-in), unless the application
 * aliased the deprecated interface to another service (1.18 style, e.g. the user locator): then that one.
 *
 * - its class implements the 1.19 interface: the 1.19 interface is aliased to it and asked; the deprecated alias stays
 *   only when the class also implements the deprecated interface (otherwise it is removed, and a decorator of it fails
 *   the build: it would wrap nothing);
 * - its class implements only the deprecated interface: it is asked exactly as in 1.18 (a deprecation at build time,
 *   except for built-ins); a decorator of the 1.19 interface then fails the build (it would wrap nothing);
 * - its class implements neither: the build fails; a class unknown before compilation (factory, synthetic) is asked
 *   through the deprecated interface, as in 1.18, without a deprecation;
 * - the application aliased the 1.19 interface itself: its service wins over a built-in (the deprecated alias then
 *   points at it when it implements the deprecated interface, and is removed otherwise); with a configured (non
 *   built-in) service that is another one, the build fails (keep only one);
 * - a decorator of the deprecated interface always runs: the deprecated interface stays asked. For the access ports
 *   (access checker, team membership, scope access) it is asked together with the 1.19 interface when that one is
 *   served by another implementation or decorated (the weaker answer wins, fail closed); for the other ports the build
 *   fails when the 1.19 interface is served by another service.
 *
 * Runs before optimization: the application's aliases and decorators are loaded, decorators are not applied yet.
 *
 * @internal
 */
final class VaultPortsPass implements CompilerPassInterface
{
    /** Parameter prefix: `nowo_vault.port.<name>.service` (service id or null = inactive), `.built_in`, `.option`, `.mode`. */
    public const PARAMETER_PREFIX = 'nowo_vault.port.';

    /** Service id prefix of the gates. */
    public const GATE_PREFIX = 'nowo_vault.port.';

    /**
     * @var array<string, array{legacy: class-string, policy: class-string, gate: class-string, both: bool}>
     */
    public const PORTS = [
        'access_checker'   => ['legacy' => VaultAccessCheckerInterface::class, 'policy' => VaultAccessPolicyInterface::class, 'gate' => VaultAccessCheckerGate::class, 'both' => true],
        'team_membership'  => ['legacy' => VaultTeamMembershipResolverInterface::class, 'policy' => VaultTeamMembershipProviderInterface::class, 'gate' => VaultTeamMembershipGate::class, 'both' => true],
        'scope_access'     => ['legacy' => VaultScopeAccessResolverInterface::class, 'policy' => VaultScopeAccessProviderInterface::class, 'gate' => VaultScopeAccessGate::class, 'both' => true],
        'reauthentication' => ['legacy' => VaultReauthenticationCheckerInterface::class, 'policy' => VaultReauthenticationProviderInterface::class, 'gate' => VaultReauthenticationGate::class, 'both' => false],
        'user_locator'     => ['legacy' => VaultUserLocatorInterface::class, 'policy' => VaultUserDirectoryInterface::class, 'gate' => VaultUserLocatorGate::class, 'both' => false],
        'health_reporter'  => ['legacy' => VaultHealthReporterInterface::class, 'policy' => VaultHealthReportProviderInterface::class, 'gate' => VaultHealthReporterGate::class, 'both' => false],
    ];

    private const PACKAGE = 'nowo-tech/vault-bundle';

    /**
     * @throws LogicException on an ambiguous or impossible wiring (see the class comment)
     */
    public function process(ContainerBuilder $container): void
    {
        foreach (self::PORTS as $name => $port) {
            $gateId = self::GATE_PREFIX . $name;
            if (!$container->hasDefinition($gateId)) {
                continue;
            }

            $configured = $container->hasParameter(self::PARAMETER_PREFIX . $name . '.service') ? $container->getParameter(self::PARAMETER_PREFIX . $name . '.service') : null;
            if (!is_string($configured) || $configured === '') {
                // Inactive port (e.g. the export is off): no gate, the consumers get null.
                $container->removeDefinition($gateId);

                continue;
            }

            $mode = $this->wire($container, $name, $port, $configured);
            $container->setParameter(self::PARAMETER_PREFIX . $name . '.mode', $mode);
            $container->getDefinition($gateId)
                ->setArguments([
                    $mode,
                    $mode === VaultPortMode::POLICY ? null : new Reference($port['legacy']),
                    $mode === VaultPortMode::LEGACY ? null : new Reference($port['policy']),
                ]);
        }
    }

    /**
     * Aliases both interfaces of one port and returns the mode its gate uses.
     *
     * @param array{legacy: class-string, policy: class-string, gate: class-string, both: bool} $port
     */
    private function wire(ContainerBuilder $container, string $name, array $port, string $configured): string
    {
        $legacy    = $port['legacy'];
        $policy    = $port['policy'];
        $option    = $container->hasParameter(self::PARAMETER_PREFIX . $name . '.option') ? (string) $container->getParameter(self::PARAMETER_PREFIX . $name . '.option') : $name;
        $builtIn   = $container->hasParameter(self::PARAMETER_PREFIX . $name . '.built_in') && (bool) $container->getParameter(self::PARAMETER_PREFIX . $name . '.built_in');
        $hostAlias = $container->hasAlias($policy) || $container->hasDefinition($policy);
        $legacyDecorators = $this->decoratorsOf($container, $legacy);
        $policyDecorators = $this->decoratorsOf($container, $policy);

        // The application's own alias of the deprecated interface (1.18 style) names the service behind the port.
        $service    = $configured;
        $hostLegacy = $container->hasAlias($legacy) && $this->resolve($container, $legacy) !== $this->resolve($container, $configured);
        if ($hostLegacy) {
            $service = (string) $container->getAlias($legacy);
            $builtIn = false;
        }

        $class            = $this->classOf($container, $service);
        $implementsPolicy = $class !== null && is_a($class, $policy, true);
        $implementsLegacy = $class === null || is_a($class, $legacy, true);
        if ($class !== null && !$implementsPolicy && !$implementsLegacy) {
            throw new LogicException(sprintf('nowo_vault: the service "%s" (%s) behind %s must implement "%s".', $service, $class, $option, $policy));
        }

        if ($hostAlias) {
            $hostService = $this->resolve($container, $policy);
            if (!$builtIn && $hostService !== $this->resolve($container, $service)) {
                throw new LogicException(sprintf('"%s" is aliased to "%s" by the application and %s names "%s": keep only one of them.', $policy, $hostService, $option, $service));
            }
            if ($legacyDecorators === []) {
                $this->keepLegacyAliasOnlyIfImplemented($container, $legacy, $hostService);

                return VaultPortMode::POLICY;
            }

            // A decorator of the deprecated interface keeps it asked. Its decorated service: the application's 1.19
            // service when it implements the deprecated interface too, else the built-in / configured one.
            $hostClass = $this->classOf($container, $hostService);
            if ($hostClass !== null && is_a($hostClass, $legacy, true)) {
                $container->setAlias($legacy, $hostService)->setPublic(false);
            }

            return $this->both($port, $name, $legacyDecorators, $policy, $legacy);
        }

        if ($implementsPolicy) {
            $container->setAlias($policy, $service)->setPublic(false);
            if (!$implementsLegacy) {
                if ($legacyDecorators !== []) {
                    throw new LogicException(sprintf('"%s" decorates the deprecated "%s", but the service "%s" behind %s does not implement it: decorate "%s" instead.', implode('", "', $legacyDecorators), $legacy, $service, $option, $policy));
                }
                $container->removeAlias($legacy);

                return VaultPortMode::POLICY;
            }

            if (!$hostLegacy) {
                $this->aliasLegacy($container, $legacy, $service, $policy);
            }
            if ($legacyDecorators === []) {
                return VaultPortMode::POLICY;
            }

            // The decorator wraps the same service: for the access ports both are asked, otherwise the deprecated one.
            return $port['both'] ? $this->both($port, $name, $legacyDecorators, $policy, $legacy) : VaultPortMode::LEGACY;
        }

        if ($policyDecorators !== []) {
            throw new LogicException(sprintf('"%s" decorates "%s", but the service "%s" behind %s does not implement it: implement "%s" on that service.', implode('", "', $policyDecorators), $policy, $service, $option, $policy));
        }
        if ($class !== null && !$builtIn) {
            trigger_deprecation(self::PACKAGE, '1.19', 'The vault service "%s" (%s) behind %s implements only the deprecated "%s"; implement "%s" instead (the deprecated interface is removed in 2.0).', $service, $class, $option, $legacy, $policy);
        }

        return VaultPortMode::LEGACY;
    }

    /**
     * Both interfaces asked (access ports), or a build failure for the other ports.
     *
     * @param array{legacy: class-string, policy: class-string, gate: class-string, both: bool} $port
     * @param list<string> $legacyDecorators
     */
    private function both(array $port, string $name, array $legacyDecorators, string $policy, string $legacy): string
    {
        if (!$port['both']) {
            throw new LogicException(sprintf('"%s" decorates the deprecated "%s" while "%s" is served by another service: the vault port %s asks only one of them. Decorate "%s" instead, or remove your alias of it.', implode('", "', $legacyDecorators), $legacy, $policy, $name, $policy));
        }

        trigger_deprecation(self::PACKAGE, '1.19', 'The vault port %s is decided by both "%s" and the deprecated "%s" (the weaker answer wins) because of the decorator(s) "%s" of the deprecated interface; migrate them to "%s" (the deprecated interface is removed in 2.0).', $name, $policy, $legacy, implode('", "', $legacyDecorators), $policy);

        return VaultPortMode::BOTH;
    }

    /**
     * The application's own 1.19 service serves the port: the deprecated alias points at it when its class implements
     * the deprecated interface too, and is removed otherwise (it would name another implementation than the one asked).
     */
    private function keepLegacyAliasOnlyIfImplemented(ContainerBuilder $container, string $legacy, string $service): void
    {
        $class = $this->classOf($container, $service);
        if ($class !== null && is_a($class, $legacy, true)) {
            $container->setAlias($legacy, $service)->setPublic(false);

            return;
        }

        $container->removeAlias($legacy);
    }

    private function aliasLegacy(ContainerBuilder $container, string $legacy, string $service, string $policy): void
    {
        $container->setAlias($legacy, $service)
            ->setPublic(false)
            ->setDeprecated(self::PACKAGE, '1.19', 'The "%alias_id%" service alias is deprecated, use "' . $policy . '" instead (removed in 2.0).');
    }

    /**
     * @return list<string> ids of the definitions that decorate the id
     */
    private function decoratorsOf(ContainerBuilder $container, string $id): array
    {
        $ids = [];
        foreach ($container->getDefinitions() as $definitionId => $definition) {
            if (($definition->getDecoratedService()[0] ?? null) === $id) {
                $ids[] = (string) $definitionId;
            }
        }

        return $ids;
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
     * @return class-string|null null when the class cannot be known before the container is compiled
     */
    private function classOf(ContainerBuilder $container, string $service): ?string
    {
        $id = $this->resolve($container, $service);
        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }
}
