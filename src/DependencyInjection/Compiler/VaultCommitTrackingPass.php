<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\EventListener\VaultConflictListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * 1.20 (REQ-RUNTIME-003 rule 6): scopes the commit tracking of the conflict answer.
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection` (its {@see CommitLedger} service AND its
 *   `nowo_transaction_doctrine.commit_tracking_middleware` exist): its own middleware already counts every commit, so
 *   the vault's middleware and ledger are removed and the conflict listener reads transaction-doctrine's ledger. No
 *   connection is wrapped twice. A `CommitLedger` service without that middleware (registered by the host, or by
 *   anything else; audit UE3-CF-2) counts nothing, so the vault keeps its own middleware and ledger.
 * - `conflict_response.commit_tracking: bundle` (default): the vault's middleware wraps the connection of the vault's
 *   entity manager (`database.entity_manager`) and the connection of every entity manager whose mapping covers the
 *   vault's entity namespace (audit UE3-CF-1: a host mapping the vault on a manager it did not name), never the host's
 *   other connections.
 * - `all`: every connection (the tag stays without a `connection` attribute).
 * - `off`: the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 *
 * @internal
 */
final class VaultCommitTrackingPass implements CompilerPassInterface
{
    /** transaction-doctrine's commit-tracking middleware, registered only with its `commit_detection` on. */
    public const TRANSACTION_DOCTRINE_MIDDLEWARE = 'nowo_transaction_doctrine.commit_tracking_middleware';

    /** Namespace of the vault's entities. */
    private const ENTITY_NAMESPACE = 'Nowo\\VaultBundle\\Entity\\';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($container->has(CommitLedger::class) && $container->has(self::TRANSACTION_DOCTRINE_MIDDLEWARE)) {
            $container->removeDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE);
            $container->removeDefinition(VaultExtension::COMMIT_LEDGER_SERVICE);
            if ($container->hasDefinition(VaultConflictListener::class)) {
                $container->getDefinition(VaultConflictListener::class)->replaceArgument(1, new Reference(CommitLedger::class));
            }

            return;
        }

        $mode = $container->hasParameter(VaultExtension::COMMIT_TRACKING_PARAMETER) ? $container->getParameter(VaultExtension::COMMIT_TRACKING_PARAMETER) : 'bundle';
        if ($mode === 'all') {
            return;
        }

        $middleware = $container->getDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE)->clearTag('doctrine.middleware');
        foreach ($this->vaultConnections($container) as $connection) {
            $middleware->addTag('doctrine.middleware', ['connection' => $connection]);
        }
    }

    /**
     * The connection of the vault's entity manager, then the connections of the other managers mapping the vault's
     * entities; each once.
     *
     * @return list<string>
     */
    private function vaultConnections(ContainerBuilder $container): array
    {
        $connections = [$this->vaultConnection($container)];
        $managers    = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        foreach (is_array($managers) ? array_keys($managers) : [] as $manager) {
            $connection = is_string($manager) && $this->mapsVaultEntities($container, $manager) ? $this->managerConnection($container, $manager) : null;
            if ($connection !== null && !in_array($connection, $connections, true)) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * Whether the manager's metadata driver chain has a driver for a namespace that covers the vault's entities.
     */
    private function mapsVaultEntities(ContainerBuilder $container, string $manager): bool
    {
        $id = sprintf('doctrine.orm.%s_metadata_driver', $manager);
        if (!$container->hasDefinition($id)) {
            return false;
        }

        foreach ($container->getDefinition($id)->getMethodCalls() as [$method, $arguments]) {
            $namespace = $arguments[1] ?? null;
            if ($method === 'addDriver' && is_string($namespace) && $namespace !== '' && str_starts_with(self::ENTITY_NAMESPACE, rtrim($namespace, '\\') . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The connection of the vault's entity manager; the default connection when it cannot be resolved.
     */
    private function vaultConnection(ContainerBuilder $container): string
    {
        $manager = $container->hasParameter('nowo_vault.entity_manager') ? $container->getParameter('nowo_vault.entity_manager') : null;
        if (!is_string($manager) || $manager === '') {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        }

        $connection = $this->managerConnection($container, is_string($manager) ? $manager : 'default');
        if ($connection !== null) {
            return $connection;
        }

        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) ? $default : 'default';
    }

    /**
     * The connection name of an entity manager definition (its first argument), null when it cannot be read.
     */
    private function managerConnection(ContainerBuilder $container, string $manager): ?string
    {
        $id       = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        return null;
    }
}
