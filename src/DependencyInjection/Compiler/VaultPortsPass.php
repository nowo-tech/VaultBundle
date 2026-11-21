<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\Port\VaultAccessCheckerGate;
use Nowo\VaultBundle\Port\VaultHealthReporterGate;
use Nowo\VaultBundle\Port\VaultReauthenticationGate;
use Nowo\VaultBundle\Port\VaultScopeAccessGate;
use Nowo\VaultBundle\Port\VaultTeamMembershipGate;
use Nowo\VaultBundle\Port\VaultUserLocatorGate;
use Nowo\VaultBundle\Security\VaultAccessPolicyInterface;
use Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface;
use Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface;
use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\Service\VaultHealthReportProviderInterface;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_search;
use function implode;
use function is_string;
use function sprintf;

/**
 * Wires the six framework-free host ports (REQ-ARCH-001 rule 3) behind the bundle's internal gates
 * (`nowo_vault.port.<name>`), which the vault services use instead of the ports themselves.
 *
 * | Port | Interface | Configured by |
 * |---|---|---|
 * | `access_checker` | {@see VaultAccessPolicyInterface} | `security.access_checker` (built-in: role lists) |
 * | `team_membership` | {@see VaultTeamMembershipProviderInterface} | `team_membership_resolver` (built-in: no teams) |
 * | `scope_access` | {@see VaultScopeAccessProviderInterface} | `scopes.access_resolver` (built-in: no scope) |
 * | `reauthentication` | {@see VaultReauthenticationProviderInterface} | `export.reauth_checker` (no built-in) |
 * | `user_locator` | {@see VaultUserDirectoryInterface} | your alias of the interface (built-in: Doctrine lookup) |
 * | `health_reporter` | {@see VaultHealthReportProviderInterface} | your alias of the interface (built-in: the reporter) |
 *
 * For each port, the service behind it is the one the configuration names (or the built-in), unless the application
 * aliased the interface itself: its service then wins over a built-in, and with a configured (non built-in) service
 * that is another one the build fails (keep only one). The interface is aliased to that service; decorators of the
 * interface or of the service apply as usual.
 *
 * Since 2.0 the deprecated twins are gone: a service behind a port whose class does not implement the interface, or
 * cannot be loaded (a class that still implements a removed interface cannot), fails the build, and so does a
 * decorator whose class does not implement it. The removed ids themselves are refused by {@see RemovedPortsPass}. A
 * class unknown before compilation (factory, synthetic) is not checked here (its gate refuses it when built).
 *
 * Runs before optimization: the application's aliases and decorators are loaded, decorators are not applied yet.
 *
 * @internal
 */
final class VaultPortsPass implements CompilerPassInterface
{
    /** Parameter prefix: `nowo_vault.port.<name>.service` (service id or null = inactive), `.built_in`, `.option`. */
    public const PARAMETER_PREFIX = 'nowo_vault.port.';

    /** Service id prefix of the gates. */
    public const GATE_PREFIX = 'nowo_vault.port.';

    /**
     * @var array<string, array{policy: class-string, gate: class-string}>
     */
    public const PORTS = [
        'access_checker'   => ['policy' => VaultAccessPolicyInterface::class, 'gate' => VaultAccessCheckerGate::class],
        'team_membership'  => ['policy' => VaultTeamMembershipProviderInterface::class, 'gate' => VaultTeamMembershipGate::class],
        'scope_access'     => ['policy' => VaultScopeAccessProviderInterface::class, 'gate' => VaultScopeAccessGate::class],
        'reauthentication' => ['policy' => VaultReauthenticationProviderInterface::class, 'gate' => VaultReauthenticationGate::class],
        'user_locator'     => ['policy' => VaultUserDirectoryInterface::class, 'gate' => VaultUserLocatorGate::class],
        'health_reporter'  => ['policy' => VaultHealthReportProviderInterface::class, 'gate' => VaultHealthReporterGate::class],
    ];

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

            $this->wire($container, $name, $port['policy'], $configured);
            $container->getDefinition($gateId)->setArguments([new Reference($port['policy'])]);
        }
    }

    /**
     * Aliases the port interface to the service behind it and checks that service and its decorators.
     *
     * @param class-string $policy
     */
    private function wire(ContainerBuilder $container, string $name, string $policy, string $configured): void
    {
        $option  = $container->hasParameter(self::PARAMETER_PREFIX . $name . '.option') ? $container->getParameter(self::PARAMETER_PREFIX . $name . '.option') : $name;
        $option  = is_string($option) ? $option : $name;
        $builtIn = $container->hasParameter(self::PARAMETER_PREFIX . $name . '.built_in') && (bool) $container->getParameter(self::PARAMETER_PREFIX . $name . '.built_in');

        $service = $configured;
        if ($container->hasAlias($policy) || $container->hasDefinition($policy)) {
            $hostService = $this->resolve($container, $policy);
            if (!$builtIn && $hostService !== $this->resolve($container, $configured)) {
                throw new LogicException(sprintf('"%s" is aliased to "%s" by the application and %s names "%s": keep only one of them.', $policy, $hostService, $option, $configured));
            }
            $service = $hostService;
        } else {
            $container->setAlias($policy, $service)->setPublic(false);
        }

        $this->assertImplements($container, $service, $policy, $option);
        $foreign = $this->decoratorsWithout($container, $service, $policy);
        if ($foreign !== []) {
            throw new LogicException(sprintf('"%s" decorates the vault port %s ("%s", behind %s) but does not implement "%s": implement it on the decorator (the deprecated interfaces were removed in 2.0, see UPGRADING.md "To 2.0.0").', implode('", "', $foreign), $policy, $service, $option, $policy));
        }
    }

    /**
     * Fails when the class of the service behind a port does not implement the interface, or cannot be loaded.
     *
     * @param class-string $policy
     */
    private function assertImplements(ContainerBuilder $container, string $service, string $policy, string $option): void
    {
        $class = $this->classOf($container, $service);
        if ($class === null) {
            return;
        }

        $reflection = $container->getReflectionClass($class, false);
        if (!$reflection instanceof ReflectionClass) {
            $removed = array_search($policy, RemovedPortsPass::REMOVED_PORTS, true);
            throw new LogicException(sprintf('nowo_vault: the class "%s" of the service "%s" behind %s cannot be loaded. A class that still implements the removed "%s" cannot load: implement "%s" instead (see UPGRADING.md "To 2.0.0").', $class, $service, $option, (string) $removed, $policy));
        }
        if (!$reflection->implementsInterface($policy)) {
            throw new LogicException(sprintf('nowo_vault: the service "%s" (%s) behind %s must implement "%s" (the deprecated interfaces were removed in 2.0, see UPGRADING.md "To 2.0.0").', $service, $class, $option, $policy));
        }
    }

    /**
     * Decorators of the service id itself, of an alias of it or of the interface, and decorators of those decorators
     * (to a fixpoint), whose class does not implement the interface or cannot be loaded. A decorator whose class cannot
     * be known before compilation (factory, synthetic) is left to its gate.
     *
     * @return list<string>
     */
    private function decoratorsWithout(ContainerBuilder $container, string $service, string $interface): array
    {
        $target = $this->resolve($container, $service);
        $ids    = [$target => true, $interface => true];
        foreach ($container->getAliases() as $aliasId => $alias) {
            if (!isset($this->policies()[(string) $aliasId]) && $this->resolve($container, (string) $aliasId) === $target) {
                $ids[(string) $aliasId] = true;
            }
        }

        $foreign = [];
        do {
            $added = false;
            foreach ($container->getDefinitions() as $definitionId => $definition) {
                $definitionId = (string) $definitionId;
                $decorated    = $definition->getDecoratedService()[0] ?? null;
                if (!is_string($decorated) || !isset($ids[$decorated]) || isset($ids[$definitionId])) {
                    continue;
                }
                $ids[$definitionId] = true;
                $added              = true;
                $class              = $this->classOf($container, $definitionId);
                if ($class !== null && $container->getReflectionClass($class, false)?->implementsInterface($interface) !== true) {
                    $foreign[] = $definitionId;
                }
            }
        } while ($added);

        return $foreign;
    }

    /**
     * @return array<string, true> the port interfaces (an alias of one of them never names a service of another port)
     */
    private function policies(): array
    {
        $policies = [];
        foreach (self::PORTS as $port) {
            $policies[$port['policy']] = true;
        }

        return $policies;
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
     * @return string|null the class of the definition behind the id, null when it cannot be known before the container
     *                     is compiled (no definition, factory, synthetic)
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

        return is_string($class) ? $class : null;
    }
}
