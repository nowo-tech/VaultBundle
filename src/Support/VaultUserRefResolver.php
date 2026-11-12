<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Turns a {@see VaultUserRef} that your code gave to the vault into a user the vault can trust (1.19.0, audit NJ-1):
 *
 * - a reference carrying a Symfony user object: that object (the vault's class policy then applies to it, as for any
 *   user in 1.18);
 * - a reference by id only: the id is looked up again through the user locator port (`nowo_vault.port.user_locator`:
 *   your {@see VaultUserDirectoryInterface} or the deprecated locator). The identifier and the role names come from
 *   that answer, never from the reference; an unknown id, or an answer for another id (byte for byte), gives null.
 *
 * Stateless.
 *
 * @internal
 */
final readonly class VaultUserRefResolver
{
    public function __construct(
        private ?VaultUserLocatorInterface $userLocator = null,
    ) {
    }

    public function resolve(VaultUserRef $ref): ?UserInterface
    {
        $user = VaultUserRefs::toUser($ref);
        if ($user instanceof UserInterface) {
            return $user;
        }
        if ($ref->id === null || !$this->userLocator instanceof VaultUserLocatorInterface) {
            return null;
        }

        $found = $this->userLocator->find($ref->id);

        return $found instanceof UserInterface && UserIdResolver::getId($found) === $ref->id ? $found : null;
    }
}
