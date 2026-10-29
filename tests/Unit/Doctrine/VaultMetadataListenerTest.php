<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;

final class VaultMetadataListenerTest extends TestCase
{
    public function testRemapsObjectAssociationMappingToConfiguredUserClass(): void
    {
        $metadata = new ClassMetadata(VaultGrant::class);
        $metadata->setPrimaryTable(['name' => 'vault_grants']);
        $metadata->mapManyToOne(['fieldName' => 'createdBy', 'targetEntity' => UserInterface::class]);

        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame(User::class, $metadata->getAssociationTargetClass('createdBy'));
        self::assertSame('prefixed_grants', $metadata->getTableName());
    }

    public function testRemapsArrayAssociationMappingToConfiguredUserClass(): void
    {
        $metadata = new ClassMetadata(VaultGrant::class);
        $metadata->setPrimaryTable(['name' => 'vault_grants']);
        $metadata->associationMappings['createdBy'] = [
            'fieldName'    => 'createdBy',
            'targetEntity' => UserInterface::class,
            'type'         => ClassMetadata::MANY_TO_ONE,
        ];

        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame(User::class, $metadata->getAssociationTargetClass('createdBy'));
    }

    public function testRemapsOneToOneUserAssociation(): void
    {
        $metadata = new ClassMetadata(VaultGrant::class);
        $metadata->setPrimaryTable(['name' => 'vault_grants']);
        $metadata->associationMappings['user'] = [
            'fieldName'    => 'user',
            'targetEntity' => UserInterface::class,
            'type'         => ClassMetadata::ONE_TO_ONE,
        ];

        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame(User::class, $metadata->getAssociationTargetClass('user'));
        self::assertTrue($metadata->isSingleValuedAssociation('user'));
    }

    public function testRejectsUnsupportedUserAssociationType(): void
    {
        $metadata = new ClassMetadata(VaultGrant::class);
        $metadata->setPrimaryTable(['name' => 'vault_grants']);
        $metadata->associationMappings['createdBy'] = [
            'fieldName'    => 'createdBy',
            'targetEntity' => UserInterface::class,
            'type'         => ClassMetadata::MANY_TO_MANY,
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsupported association type for createdBy');

        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));
    }

    public function testPrefixesItemJoinTableAndSettingsTable(): void
    {
        $item = new ClassMetadata(VaultItem::class);
        $item->setPrimaryTable(['name' => 'vault_items']);
        $item->mapManyToMany([
            'fieldName'    => 'tags',
            'targetEntity' => VaultTag::class,
            'joinTable'    => ['name' => 'vault_item_tag'],
        ]);
        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($item, $this->createStub(EntityManagerInterface::class)));

        self::assertSame('prefixed_items', $item->getTableName());
        $tags = $item->getAssociationMapping('tags');
        self::assertSame('prefixed_item_tag', is_array($tags) ? $tags['joinTable']['name'] : $tags->joinTable->name);

        $settings = new ClassMetadata(VaultSettings::class);
        $settings->setPrimaryTable(['name' => 'vault_settings']);
        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($settings, $this->createStub(EntityManagerInterface::class)));

        self::assertSame('prefixed_settings', $settings->getTableName());
    }

    public function testIgnoresForeignEntities(): void
    {
        $metadata = new ClassMetadata(stdClass::class);
        $metadata->setPrimaryTable(['name' => 'host_table']);

        $this->listener()->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class)));

        self::assertSame('host_table', $metadata->getTableName());
    }

    private function listener(): VaultMetadataListener
    {
        return new VaultMetadataListener(
            'prefixed_items',
            'prefixed_folders',
            'prefixed_grants',
            'prefixed_tags',
            'prefixed_item_tag',
            'prefixed_settings',
            'prefixed_extension_tokens',
            '\\' . User::class,
        );
    }
}
