<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\VaultBundle\DependencyInjection\Compiler\ConditionalMappingPass;
use Nowo\VaultBundle\Doctrine\VaultFeatureMappingDriver;
use Nowo\VaultBundle\Doctrine\VaultUnmappedTablesSchemaFilter;
use Nowo\VaultBundle\Entity\VaultSettings;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * 1.18.0 database.conditional_mapping: decoration of the vault entity manager's metadata driver and the schema filter
 * tagged for the entity manager's connection.
 */
final class ConditionalMappingPassTest extends TestCase
{
    public function testNothingIsRegisteredWithoutUnmappedClasses(): void
    {
        $container = $this->container('vault', []);
        (new ConditionalMappingPass())->process($container);
        (new ConditionalMappingPass())->process(new ContainerBuilder());

        self::assertFalse($container->hasDefinition(ConditionalMappingPass::DRIVER_ID));
        self::assertFalse($container->hasDefinition(ConditionalMappingPass::SCHEMA_FILTER_ID));
    }

    public function testDecoratesTheDriverAndTagsTheFilterForTheEntityManagerConnection(): void
    {
        $container = $this->container('vault', [VaultSettings::class], ['vault_settings']);
        $container->setDefinition('doctrine.orm.vault_entity_manager', new Definition(stdClass::class, [new Reference('doctrine.dbal.secrets_connection')]));

        (new ConditionalMappingPass())->process($container);

        $driver = $container->getDefinition(ConditionalMappingPass::DRIVER_ID);
        self::assertSame(VaultFeatureMappingDriver::class, $driver->getClass());
        self::assertSame(['doctrine.orm.vault_metadata_driver', null, 0], $driver->getDecoratedService());
        self::assertSame([VaultSettings::class], $driver->getArgument(1));

        $filter = $container->getDefinition(ConditionalMappingPass::SCHEMA_FILTER_ID);
        self::assertSame(VaultUnmappedTablesSchemaFilter::class, $filter->getClass());
        self::assertSame([['connection' => 'secrets']], $filter->getTag('doctrine.dbal.schema_filter'));
        self::assertSame([['vault_settings']], $filter->getArguments());
    }

    public function testFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('default', [VaultSettings::class], ['vault_settings']);
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition(stdClass::class, ['not-a-reference']));

        (new ConditionalMappingPass())->process($container);

        self::assertTrue($container->hasDefinition(ConditionalMappingPass::DRIVER_ID));
        self::assertSame([[]], $container->getDefinition(ConditionalMappingPass::SCHEMA_FILTER_ID)->getTag('doctrine.dbal.schema_filter'));

        $noEm = $this->container('default', [VaultSettings::class], ['vault_settings']);
        (new ConditionalMappingPass())->process($noEm);
        self::assertSame([[]], $noEm->getDefinition(ConditionalMappingPass::SCHEMA_FILTER_ID)->getTag('doctrine.dbal.schema_filter'));

        $aliased = new ContainerBuilder();
        $aliased->setParameter('nowo_vault.entity_manager', 'default');
        $aliased->setParameter('nowo_vault.unmapped_entity_classes', [VaultSettings::class]);
        $aliased->setParameter('nowo_vault.unmapped_tables', []);
        $aliased->setDefinition('app.driver', new Definition(stdClass::class));
        $aliased->setAlias('doctrine.orm.default_metadata_driver', 'app.driver');
        (new ConditionalMappingPass())->process($aliased);
        self::assertTrue($aliased->hasDefinition(ConditionalMappingPass::DRIVER_ID));
    }

    public function testAMissingMetadataDriverFailsTheCompileInsteadOfOnlyHidingTables(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_vault.entity_manager', 'vault');
        $container->setParameter('nowo_vault.unmapped_entity_classes', [VaultSettings::class]);
        $container->setParameter('nowo_vault.unmapped_tables', ['vault_settings']);

        try {
            (new ConditionalMappingPass())->process($container);
            self::fail('A missing metadata driver must fail the compile.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('doctrine.orm.vault_metadata_driver', $exception->getMessage());
        }
        self::assertFalse($container->hasDefinition(ConditionalMappingPass::SCHEMA_FILTER_ID));
    }

    public function testNoFilterWithoutTables(): void
    {
        $container = $this->container('default', [VaultSettings::class], []);
        (new ConditionalMappingPass())->process($container);

        self::assertTrue($container->hasDefinition(ConditionalMappingPass::DRIVER_ID));
        self::assertFalse($container->hasDefinition(ConditionalMappingPass::SCHEMA_FILTER_ID));
    }

    /**
     * @param list<string> $classes
     * @param list<string> $tables
     */
    private function container(string $em, array $classes, array $tables = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_vault.entity_manager', $em);
        $container->setParameter('nowo_vault.unmapped_entity_classes', $classes);
        $container->setParameter('nowo_vault.unmapped_tables', $tables);
        $container->setDefinition(sprintf('doctrine.orm.%s_metadata_driver', $em), new Definition(stdClass::class));

        return $container;
    }
}
