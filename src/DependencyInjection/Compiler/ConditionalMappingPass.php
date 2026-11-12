<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\Doctrine\VaultFeatureMappingDriver;
use Nowo\VaultBundle\Doctrine\VaultUnmappedTablesSchemaFilter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * `database.conditional_mapping: true` (1.18.0): leaves the entities of disabled features out of the vault entity
 * manager (decorates `doctrine.orm.<em>_metadata_driver` with {@see VaultFeatureMappingDriver}) and hides their tables
 * from the schema tools of the entity manager's connection ({@see VaultUnmappedTablesSchemaFilter}, tag
 * `doctrine.dbal.schema_filter`), so an existing table is never dropped automatically. Registered before DoctrineBundle's
 * DbalSchemaFilterPass (priority 10). No-op when nothing is unmapped; the schema filter is registered only together with
 * the driver decoration, and a missing metadata driver fails the compile (NC-4).
 */
final class ConditionalMappingPass implements CompilerPassInterface
{
    public const DRIVER_ID = 'nowo_vault.feature_mapping_driver';

    public const SCHEMA_FILTER_ID = 'nowo_vault.unmapped_tables_schema_filter';

    public function process(ContainerBuilder $container): void
    {
        $classes = $container->hasParameter('nowo_vault.unmapped_entity_classes') ? $container->getParameter('nowo_vault.unmapped_entity_classes') : [];
        $tables  = $container->hasParameter('nowo_vault.unmapped_tables') ? $container->getParameter('nowo_vault.unmapped_tables') : [];
        $em      = $container->hasParameter('nowo_vault.entity_manager') ? $container->getParameter('nowo_vault.entity_manager') : null;
        if (!is_array($classes) || $classes === [] || !is_string($em) || $em === '') {
            return;
        }

        $driverId = sprintf('doctrine.orm.%s_metadata_driver', $em);
        if (!$container->hasDefinition($driverId) && !$container->hasAlias($driverId)) {
            // NC-4: hiding the tables without unmapping the entities would let the ORM write to tables the schema tools
            // no longer manage; fail the compile instead.
            throw new LogicException(sprintf('nowo_vault.database.conditional_mapping is on, but the metadata driver "%s" of the vault entity manager "%s" does not exist, so the disabled-feature entities cannot be unmapped. Check nowo_vault.database.entity_manager (and that DoctrineBundle maps the vault entities in that entity manager), or set conditional_mapping to false.', $driverId, $em));
        }
        $container->setDefinition(self::DRIVER_ID, (new Definition(VaultFeatureMappingDriver::class))
            ->setDecoratedService($driverId)
            ->setArguments([new Reference(self::DRIVER_ID . '.inner'), $classes]));

        if (!is_array($tables) || $tables === []) {
            return;
        }

        $container->setDefinition(self::SCHEMA_FILTER_ID, (new Definition(VaultUnmappedTablesSchemaFilter::class))
            ->setArguments([$tables])
            ->addTag('doctrine.dbal.schema_filter', ($connection = $this->connection($container, $em)) !== null ? ['connection' => $connection] : []));
    }

    /**
     * Connection of the vault entity manager (first argument of its definition), null = the default connection.
     */
    private function connection(ContainerBuilder $container, string $em): ?string
    {
        $id = sprintf('doctrine.orm.%s_entity_manager', $em);
        if (!$container->hasDefinition($id)) {
            return null;
        }

        $arguments = $container->getDefinition($id)->getArguments();
        $first     = $arguments[0] ?? null;
        if ($first instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/', (string) $first, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
