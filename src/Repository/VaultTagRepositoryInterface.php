<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultTag;

/**
 * Persistence contract for vault tags.
 */
interface VaultTagRepositoryInterface
{
    /**
     * Persists the tag.
     */
    public function save(VaultTag $tag): void;

    /**
     * Finds a tag by id.
     */
    public function findById(string $id): ?VaultTag;

    /**
     * Finds the creator's tag with the given name, or null.
     */
    public function findOneByCreatorAndName(object $creator, string $name): ?VaultTag;

    /**
     * Returns the creator's tags.
     *
     * @return list<VaultTag>
     */
    public function findByCreator(object $creator): array;

    /**
     * Removes the tag.
     */
    public function remove(VaultTag $tag): void;
}
