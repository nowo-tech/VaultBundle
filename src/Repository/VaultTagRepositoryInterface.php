<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultTag;

interface VaultTagRepositoryInterface
{
    public function save(VaultTag $tag): void;

    public function findById(string $id): ?VaultTag;

    public function findOneByCreatorAndName(object $creator, string $name): ?VaultTag;

    /**
     * @return list<VaultTag>
     */
    public function findByCreator(object $creator): array;

    public function remove(VaultTag $tag): void;
}
