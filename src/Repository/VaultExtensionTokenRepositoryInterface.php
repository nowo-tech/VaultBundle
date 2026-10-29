<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Entity\VaultExtensionToken;

/**
 * Persistence contract for browser extension bearer tokens (stored as hashes).
 */
interface VaultExtensionTokenRepositoryInterface
{
    /**
     * Persists the token.
     */
    public function save(VaultExtensionToken $token): void;

    /**
     * Removes the token.
     */
    public function remove(VaultExtensionToken $token): void;

    /**
     * Finds a non-expired token by its hash, or null.
     */
    public function findValidByTokenHash(string $tokenHash): ?VaultExtensionToken;

    /**
     * Returns the tokens of a user.
     *
     * @return list<VaultExtensionToken>
     */
    public function findByUser(object $user): array;

    /**
     * Deletes expired tokens and returns how many were removed.
     */
    public function removeExpired(): int;
}
