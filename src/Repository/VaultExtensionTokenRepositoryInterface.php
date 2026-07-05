<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultExtensionToken;

interface VaultExtensionTokenRepositoryInterface
{
    public function save(VaultExtensionToken $token): void;

    public function remove(VaultExtensionToken $token): void;

    public function findValidByTokenHash(string $tokenHash): ?VaultExtensionToken;

    /**
     * @return list<VaultExtensionToken>
     */
    public function findByUser(object $user): array;

    public function removeExpired(): int;
}
