<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\VaultBundle\DependencyInjection\VaultExtension;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function dirname;
use function get_object_vars;
use function is_bool;
use function ksort;
use function sys_get_temp_dir;

use const CASE_LOWER;
use const PHP_VERSION_ID;

/**
 * 1.19.0: the shipped XML mapping (src/Resources/config/doctrine/*.orm.xml) describes exactly the tables, columns,
 * indexes, constraints and associations of the entity attributes, in the relation and id user mappings (after the
 * bundle's metadata listener), so `database.mapping_type: xml` (or a host mapping the vault from that directory) moves no
 * column and drops nothing.
 */
#[CoversNothing]
final class XmlMappingParityTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function entities(): iterable
    {
        foreach ([VaultItem::class, VaultFolder::class, VaultGrant::class, VaultTag::class, VaultSettings::class, VaultExtensionToken::class] as $class) {
            foreach ([VaultUserMapping::RELATION, VaultUserMapping::ID] as $mapping) {
                yield $class . ' / ' . $mapping => [$class, $mapping];
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('entities')]
    public function theXmlMetadataMatchesTheAttributes(string $class, string $mapping): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']), $mapping)->getClassMetadata($class);
        $xml       = $this->manager($this->xmlDriver(), $mapping)->getClassMetadata($class);

        self::assertSame($attribute->getTableName(), $xml->getTableName());
        self::assertSame($attribute->getIdentifier(), $xml->getIdentifier());
        self::assertSame($attribute->generatorType, $xml->generatorType);
        self::assertEquals($this->sorted($attribute->table['indexes'] ?? []), $this->sorted($xml->table['indexes'] ?? []));
        self::assertEquals($this->sorted($attribute->table['uniqueConstraints'] ?? []), $this->sorted($xml->table['uniqueConstraints'] ?? []));
        self::assertEquals($this->fields($attribute), $this->fields($xml));
        self::assertEquals($this->associations($attribute), $this->associations($xml));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mappings(): iterable
    {
        yield 'relation' => [VaultUserMapping::RELATION];
        yield 'id' => [VaultUserMapping::ID];
    }

    #[Test]
    #[DataProvider('mappings')]
    public function theXmlSchemaHasNoDifference(string $mapping): void
    {
        $attribute = $this->manager(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']), $mapping);
        $xml       = $this->manager($this->xmlDriver(), $mapping);
        $from      = (new SchemaTool($attribute))->getSchemaFromMetadata($attribute->getMetadataFactory()->getAllMetadata());
        $to        = (new SchemaTool($xml))->getSchemaFromMetadata($xml->getMetadataFactory()->getAllMetadata());

        self::assertCount(7, $xml->getMetadataFactory()->getAllMetadata(), 'six vault entities and the host user');
        self::assertTrue($attribute->getConnection()->createSchemaManager()->createComparator()->compareSchemas($from, $to)->isEmpty());
    }

    private function xmlDriver(): MappingDriver
    {
        return new SimplifiedXmlDriver([VaultExtension::XML_MAPPING_DIR => 'Nowo\\VaultBundle\\Entity']);
    }

    private function manager(MappingDriver $vault, string $mapping): EntityManager
    {
        $chain = new MappingDriverChain();
        $chain->addDriver($vault, 'Nowo\\VaultBundle\\Entity');
        $chain->addDriver(new AttributeDriver([dirname(__DIR__, 2) . '/App/Entity']), 'Nowo\\VaultBundle\\Tests\\App\\Entity');
        $config = new Configuration();
        $config->setMetadataDriverImpl($chain);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-vault-xml-test-proxies');
        $config->setProxyNamespace('NowoVaultXmlTestProxies');
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new VaultMetadataListener('vault_items', 'vault_folders', 'vault_grants', 'vault_tags', 'vault_item_tag', 'vault_settings', 'vault_extension_tokens', User::class, $mapping));

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config, $events);
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(ClassMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->fieldMappings as $name => $mapping) {
            $vars = get_object_vars($mapping);
            // XML options are strings: a boolean default is written 0 / 1.
            foreach ($vars['options'] ?? [] as $option => $value) {
                $vars['options'][$option] = is_bool($value) ? (string) (int) $value : $value;
            }
            $fields[$name] = $vars;
        }
        ksort($fields);

        return $fields;
    }

    /**
     * @param ClassMetadata<object> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function associations(ClassMetadata $metadata): array
    {
        $associations = [];
        foreach ($metadata->associationMappings as $name => $mapping) {
            $vars                = get_object_vars($mapping);
            $vars['joinColumns'] = array_map(get_object_vars(...), $vars['joinColumns'] ?? []);
            if (isset($vars['joinTable'])) {
                $vars['joinTable'] = get_object_vars($vars['joinTable']);
                foreach (['joinColumns', 'inverseJoinColumns'] as $side) {
                    $vars['joinTable'][$side] = array_map(get_object_vars(...), $vars['joinTable'][$side] ?? []);
                }
            }
            $associations[$name] = $vars;
        }
        ksort($associations);

        return $associations;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return array<string, array<string, mixed>>
     */
    private function sorted(array $definitions): array
    {
        ksort($definitions);

        return $definitions;
    }
}
