<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\ValueObject\VaultUserRef;

/**
 * Loads a vault user by id (1.19.0; since 2.0 the only user lookup port: the deprecated `VaultUserLocatorInterface` was
 * removed). Framework-free (REQ-ARCH-001 rule 3). Used for browser extension tokens with the id mapping, scope grantee
 * checks and the GDPR export / erasure.
 *
 * Alias this interface to your own service (a domain user repository, no Doctrine needed): return
 * `VaultUserRef::ofId($id, …)` for a known user, null otherwise. The built-in directory reads `user_class` through
 * Doctrine.
 */
interface VaultUserDirectoryInterface
{
    /**
     * The user with this id, or null (unknown id, or an id of the wrong type).
     */
    public function findUser(string $id): ?VaultUserRef;
}
