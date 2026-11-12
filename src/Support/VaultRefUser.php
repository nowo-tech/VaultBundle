<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The vault's own stand-in for a user your code gave as a {@see VaultUserRef} without a user object (1.19.0): it
 * carries the reference's id, identifier and role names, so the vault's services, which work on users, can serve the
 * 1.19 ports (`VaultUserRef::ofId()`, a {@see VaultUserDirectoryInterface} returning ids). It is a vault user by its id,
 * like a `user_class` instance ({@see VaultUserClassPolicy}); with the relation mapping it is stored as
 * `getReference(user_class, id)`. Never a security token user, never stored.
 *
 * @internal
 */
final readonly class VaultRefUser implements UserInterface
{
    public function __construct(
        private VaultUserRef $ref,
        private string $id,
    ) {
    }

    /**
     * The stand-in of a reference with an id; null for a reference without one.
     */
    public static function of(VaultUserRef $ref): ?self
    {
        return $ref->id !== null ? new self($ref, $ref->id) : null;
    }

    /**
     * The reference this stand-in was built from.
     */
    public function ref(): VaultUserRef
    {
        return $this->ref;
    }

    /**
     * The vault user id, as given.
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function getUserIdentifier(): string
    {
        return $this->ref->identifier !== '' ? $this->ref->identifier : $this->id;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->ref->roles;
    }

    /**
     * Nothing to erase (no credentials).
     */
    public function eraseCredentials(): void
    {
    }
}
