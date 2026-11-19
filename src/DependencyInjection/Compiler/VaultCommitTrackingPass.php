<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\EventListener\VaultConflictListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.20 (REQ-RUNTIME-003 rule 6): scopes the commit tracking of the conflict answer.
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection` (its {@see CommitLedger} service exists):
 *   its own middleware already counts every commit, so the vault's middleware and ledger are removed and the conflict
 *   listener reads transaction-doctrine's ledger. No connection is wrapped twice.
 * - `conflict_response.commit_tracking: bundle` (default): the vault's middleware wraps only the connection of the
 *   vault's entity manager (`database.entity_manager`), never the host's other connections.
 * - `all`: every connection (the tag stays without a `connection` attribute).
 * - `off`: the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 *
 * @internal
 */
final class VaultCommitTrackingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($container->has(CommitLedger::class)) {
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

        $container->getDefinition(VaultExtension::COMMIT_TRACKING_MIDDLEWARE)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $this->vaultConnection($container)]);
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

        $id       = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) ? $default : 'default';
    }
}
