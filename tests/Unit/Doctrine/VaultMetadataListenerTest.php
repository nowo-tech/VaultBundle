<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\VaultBundle\Doctrine\VaultMetadataListener;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Tests\App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

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
