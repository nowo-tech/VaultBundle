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
use function get_debug_type;
use function in_array;
use function is_array;
use function is_scalar;
use function ltrim;
use function sprintf;

/**
 * Applies configurable table prefix and user entity mapping to vault entities: relations to `user_class` (default) or,
 * with `nowo_vault.user_mapping: id` (1.17.0), string user id columns without any relation to the host.
 */
final readonly class VaultMetadataListener
{
    /** Fields of the vault entities that hold a host user. */
    public const USER_FIELDS = ['creator', 'createdBy', 'user'];

    /** Length of a user id column with the id mapping (ints, UUIDs, ULIDs and other string ids). */
    public const USER_ID_LENGTH = 128;

    /**
     * Creates the listener with the resolved (prefixed) table names and user class.
     */
    public function __construct(
        private string $itemsTableName,
        private string $foldersTableName,
        private string $grantsTableName,
        private string $tagsTableName,
        private string $itemTagsTableName,
        private string $settingsTableName,
        private string $extensionTokensTableName,
        private string $userClass,
        private string $userMapping = VaultUserMapping::RELATION,
    ) {
    }

    /**
     * Applies the configured table names (and user mapping) to vault entity metadata.
     */
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
            $newMapping = array_replace_recursive(
                $this->mappingToArray($metadata->associationMappings['tags']),
                ['joinTable' => ['name' => $this->itemTagsTableName]],
            );
            $newMapping['fieldName'] = 'tags';
            unset($metadata->associationMappings['tags']);
            $metadata->mapManyToMany($newMapping);
        }

        if (!in_array($class, [VaultItem::class, VaultFolder::class, VaultGrant::class, VaultTag::class, VaultExtensionToken::class], true)) {
            return;
        }

        foreach (self::USER_FIELDS as $field) {
            if (!isset($metadata->associationMappings[$field])) {
                continue;
            }

            if ($this->userMapping === VaultUserMapping::ID) {
                $this->mapUserIdColumn($metadata, $field);
            } else {
                $this->remapUserAssociation($metadata, $field);
            }
        }
    }

    /**
     * Id mapping (1.17.0): replaces the relation to the host user by a string column of the same name holding the
     * user id (no foreign key, no join to the host users table).
     *
     * @param ClassMetadata<object> $metadata
     */
    private function mapUserIdColumn(ClassMetadata $metadata, string $fieldName): void
    {
        $mapping = $this->mappingToArray($metadata->associationMappings[$fieldName]);
        /** @var array{name?: string, nullable?: bool} $joinColumn */
        $joinColumn = is_array($mapping['joinColumns'] ?? null) && is_array($mapping['joinColumns'][0] ?? null) ? $mapping['joinColumns'][0] : [];

        unset($metadata->associationMappings[$fieldName]);

        $metadata->mapField([
            'fieldName'  => $fieldName,
            'columnName' => $joinColumn['name'] ?? $fieldName . '_id',
            'type'       => 'string',
            'length'     => self::USER_ID_LENGTH,
            'nullable'   => $joinColumn['nullable'] ?? true,
        ]);
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function remapUserAssociation(ClassMetadata $metadata, string $fieldName): void
    {
        $newMapping = array_replace_recursive(
            $this->mappingToArray($metadata->associationMappings[$fieldName]),
            ['targetEntity' => ltrim($this->userClass, '\\')],
        );
        $newMapping['fieldName'] = $fieldName;
        $type                    = $newMapping['type'] ?? null;

        unset($metadata->associationMappings[$fieldName]);

        match ($type) {
            ClassMetadata::MANY_TO_ONE => $metadata->mapManyToOne($newMapping),
            ClassMetadata::ONE_TO_ONE  => $metadata->mapOneToOne($newMapping),
            default                    => throw new LogicException(sprintf('Unsupported association type for %s: %s', $fieldName, is_scalar($type) ? (string) $type : get_debug_type($type))),
        };
    }

    /**
     * Doctrine ORM 3 exposes association mappings as objects, ORM 2 as arrays.
     *
     * @return array<string, mixed>
     */
    private function mappingToArray(mixed $mapping): array
    {
        return $mapping instanceof AssociationMapping ? $mapping->toArray() : (array) $mapping;
    }
}
