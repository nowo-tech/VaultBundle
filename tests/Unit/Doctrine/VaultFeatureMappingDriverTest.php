<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\VaultBundle\Doctrine\VaultFeatureMappingDriver;
use Nowo\VaultBundle\Doctrine\VaultUnmappedTablesSchemaFilter;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use PHPUnit\Framework\TestCase;

/**
 * 1.18.0 database.conditional_mapping: the decorated metadata driver and the schema asset filter.
 */
final class VaultFeatureMappingDriverTest extends TestCase
{
    public function testUnmappedClassesAreTransientAndLeftOutOfTheClassList(): void
    {
        $inner = $this->createMock(MappingDriver::class);
        $inner->method('getAllClassNames')->willReturn([VaultItem::class, VaultSettings::class, VaultExtensionToken::class]);
        $inner->method('isTransient')->willReturnCallback(static fn (string $class): bool => $class === 'App\\NotAnEntity');
        $metadata = new ClassMetadata(VaultItem::class);
        $inner->expects(self::once())->method('loadMetadataForClass')->with(VaultItem::class, $metadata);

        $driver = new VaultFeatureMappingDriver($inner, ['\\' . VaultSettings::class, VaultExtensionToken::class]);

        self::assertSame([VaultItem::class], $driver->getAllClassNames());
        self::assertTrue($driver->isTransient(VaultSettings::class));
        self::assertTrue($driver->isTransient('\\' . VaultExtensionToken::class));
        self::assertTrue($driver->isTransient('App\\NotAnEntity'));
        self::assertFalse($driver->isTransient(VaultItem::class));

        $driver->loadMetadataForClass(VaultItem::class, $metadata);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('database.conditional_mapping');
        $driver->loadMetadataForClass(VaultSettings::class, new ClassMetadata(VaultSettings::class));
    }

    public function testTheSchemaFilterHidesOnlyTheUnmappedTables(): void
    {
        $filter = new VaultUnmappedTablesSchemaFilter(['vault_settings', 'Vault_Extension_Tokens']);

        self::assertFalse($filter('vault_settings'));
        self::assertFalse($filter('public.vault_settings'));
        self::assertFalse($filter('"vault_extension_tokens"'));
        self::assertFalse($filter('`VAULT_SETTINGS`'));
        self::assertFalse($filter(new Table('vault_extension_tokens')));
        self::assertTrue($filter('vault_items'));
        self::assertTrue($filter('vault_settings_seq'));
        self::assertTrue($filter(new Table('app_settings')));
    }
}
