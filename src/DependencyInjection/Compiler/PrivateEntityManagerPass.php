<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\Connection;
use Nowo\VaultBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\VaultBundle\Doctrine\VaultEntityManager;
use ReflectionMethod;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function implode;
use function interface_exists;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Private entity manager wiring (1.19.0, REQ-ARCH-001 rule 6):
 *
 * - the source manager is `nowo_vault.database.entity_manager` (default `default`), the manager the vault used in 1.18
 *   (no derivation: 1.18 always used the configured name). A name DoctrineBundle does not know fails the build with a
 *   clear message (1.18 failed on a missing `doctrine.orm.<name>_entity_manager` service);
 * - attaches {@see PrivateEntityManagerMiddleware} only to the connection of that manager (the first argument of its
 *   definition; the default connection when it cannot be read): the host's other connections are never wrapped, and the
 *   middleware and the private manager always see the same connection.
 *
 * Runs before DoctrineBundle's MiddlewaresPass.
 *
 * @internal
 */
final class PrivateEntityManagerPass implements CompilerPassInterface
{
    /**
     * The rollback middleware is written against the doctrine/dbal 4 driver API; with DBAL 3 the private manager runs
     * without it (no replacement after a rollback, see docs/CONFIGURATION.md).
     */
    public static function supportsRollbackMiddleware(): bool
    {
        if (!interface_exists(Connection::class)) {
            return false; // @codeCoverageIgnore
        }

        return (string) (new ReflectionMethod(Connection::class, 'rollBack'))->getReturnType() === 'void';
    }

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(VaultEntityManager::class)) {
            return;
        }

        $manager  = $container->hasParameter('nowo_vault.entity_manager') ? $container->getParameter('nowo_vault.entity_manager') : 'default';
        $manager  = is_string($manager) && $manager !== '' ? $manager : 'default';
        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : null;
        if (is_array($managers) && $managers !== [] && !isset($managers[$manager])) {
            throw new InvalidConfigurationException(sprintf('nowo_vault.database.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Set it to the manager that maps the vault entities.', $manager, implode('", "', array_keys($managers))));
        }

        if (!$container->hasDefinition(PrivateEntityManagerMiddleware::class)) {
            return;
        }

        $id         = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument   = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        $connection = $argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1 ? $match[1] : null;
        if ($connection === null) {
            $default    = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connection = is_string($default) && $default !== '' ? $default : 'default';
        }

        $container->getDefinition(PrivateEntityManagerMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $connection]);
    }
}
