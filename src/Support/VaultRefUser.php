<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The vault's own stand-in for a user that your {@see VaultUserDirectoryInterface} returned as a {@see VaultUserRef}
 * without a user object (1.19.0, audit NJ-1): it carries the id, identifier and role names **of the directory's
 * answer**, so the vault's services, which work on users, can serve a host whose users are no Symfony objects. Only the
 * user-locator gate builds it, for the id it was asked for (byte for byte): a reference built elsewhere
 * (`VaultUserRef::ofId()` in a call, an authenticator result, a listener) never becomes one; the vault resolves its id
 * through the directory instead. It is a vault user by its id ({@see VaultUserClassPolicy}); with the relation mapping
 * it is stored as `getReference(user_class, id)`. Never a security token user, never stored.
 *
 * @internal
 */
final readonly class VaultRefUser implements UserInterface
{
    private function __construct(
        private VaultUserRef $ref,
        private string $id,
    ) {
    }

    /**
     * The stand-in of the directory's answer for an id; null for an answer without an id.
     *
     * @internal only the user-locator gate calls it
     */
    public static function fromDirectory(VaultUserRef $ref): ?self
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
        if ($this->ref->identifier !== '') {
            return $this->ref->identifier;
        }

        // A reference id is never blank (VaultUserRef refuses it).
        return $this->id !== '' ? $this->id : 'vault-user';
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
