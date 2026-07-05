<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultSettings;
use Nowo\VaultBundle\Entity\VaultTag;

use function array_replace_recursive;
use function in_array;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table prefix and user entity mapping to vault entities.
 */
final readonly class VaultMetadataListener
{
    public function __construct(
        private string $itemsTableName,
        private string $foldersTableName,
        private string $grantsTableName,
        private string $tagsTableName,
        private string $itemTagsTableName,
        private string $settingsTableName,
        private string $extensionTokensTableName,
        private string $userClass,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $class    = $metadata->getName();

        match ($class) {
            VaultItem::class           => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->itemsTableName])),
            VaultFolder::class         => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->foldersTableName])),
            VaultGrant::class          => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->grantsTableName])),
            VaultTag::class            => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->tagsTableName])),
            VaultSettings::class       => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->settingsTableName])),
            VaultExtensionToken::class => $metadata->setPrimaryTable(array_merge($metadata->table, ['name' => $this->extensionTokensTableName])),
            default                    => null,
        };

        if ($class === VaultItem::class && isset($metadata->associationMappings['tags'])) {
            $mapping = $metadata->associationMappings['tags'];
            if ($mapping instanceof AssociationMapping) {
                $newMapping = array_replace_recursive(
                    $mapping->toArray(),
                    ['joinTable' => ['name' => $this->itemTagsTableName]],
                );
                $newMapping['fieldName'] = $mapping->fieldName;
                unset($metadata->associationMappings['tags']);
                $metadata->mapManyToMany($newMapping);
            }
        }

        if (!in_array($class, [VaultItem::class, VaultFolder::class, VaultGrant::class, VaultTag::class, VaultExtensionToken::class], true)) {
            return;
        }

        foreach (['creator', 'createdBy', 'user'] as $field) {
            if (isset($metadata->associationMappings[$field])) {
                $this->remapUserAssociation($metadata, $field);
            }
        }
    }

    private function remapUserAssociation(ClassMetadata $metadata, string $fieldName): void
    {
        $mapping      = $metadata->associationMappings[$fieldName];
        $targetEntity = ltrim($this->userClass, '\\');

        if ($mapping instanceof AssociationMapping) {
            $newMapping = array_replace_recursive(
                $mapping->toArray(),
                ['targetEntity' => $targetEntity],
            );
            $newMapping['fieldName'] = $mapping->fieldName;

            unset($metadata->associationMappings[$fieldName]);

            match ($mapping->type()) {
                ClassMetadata::MANY_TO_ONE => $metadata->mapManyToOne($newMapping),
                ClassMetadata::ONE_TO_ONE  => $metadata->mapOneToOne($newMapping),
                default                    => throw new LogicException(sprintf('Unsupported association type for %s: %d', $fieldName, $mapping->type())),
            };

            return;
        }
    }
}
