<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultSettings;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function ltrim;
use function sprintf;

/**
 * Decorates the metadata driver of the vault entity manager (1.18.0, `database.conditional_mapping: true`): the
 * entities of a feature that is off ({@see VaultSettings} without `config_storage.enabled`,
 * {@see VaultExtensionToken} without `browser_extension.enabled`) become transient, so the
 * schema tool, `doctrine:schema:validate` and `doctrine:migrations:diff` no longer see them. Every other class is
 * delegated unchanged. Built once per container: no request state.
 */
final readonly class VaultFeatureMappingDriver implements MappingDriver
{
    /** @var list<string> */
    private array $unmappedClasses;

    /**
     * @param list<string> $unmappedClasses entity classes left out of the mapping
     */
    public function __construct(
        private MappingDriver $inner,
        array $unmappedClasses,
    ) {
        $this->unmappedClasses = array_map(static fn (string $class): string => ltrim($class, '\\'), $unmappedClasses);
    }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        if ($this->isUnmapped($className)) {
            throw new MappingException(sprintf('Class "%s" is not mapped: its nowo_vault feature is disabled (database.conditional_mapping).', $className));
        }

        $this->inner->loadMetadataForClass($className, $metadata);
    }

    /**
     * @return list<class-string>
     */
    public function getAllClassNames(): array
    {
        return array_values(array_filter($this->inner->getAllClassNames(), fn (string $class): bool => !$this->isUnmapped($class)));
    }

    public function isTransient(string $className): bool
    {
        return $this->isUnmapped($className) || $this->inner->isTransient($className);
    }

    private function isUnmapped(string $className): bool
    {
        return in_array(ltrim($className, '\\'), $this->unmappedClasses, true);
    }
}
