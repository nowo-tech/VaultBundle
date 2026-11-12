<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\ValueObject;

use InvalidArgumentException;
use Nowo\VaultBundle\Internal\HiddenValues;

use function array_values;
use function in_array;
use function is_object;
use function is_string;
use function trim;

/**
 * Framework-free reference to a vault user (1.19.0, REQ-ARCH-001 rule 3): what the 1.19 ports
 * ({@see \Nowo\VaultBundle\Security\VaultAccessPolicyInterface}, {@see \Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface},
 * {@see \Nowo\VaultBundle\Security\VaultScopeAccessProviderInterface}, {@see \Nowo\VaultBundle\Security\VaultReauthenticationProviderInterface},
 * {@see \Nowo\VaultBundle\Support\VaultUserDirectoryInterface}, {@see \Nowo\VaultBundle\Service\VaultHealthReportProviderInterface})
 * and the event accessors give and take instead of the Symfony `UserInterface`.
 *
 * Its properties hold the user id (the string form of the host user's `getId()`, as is: never trimmed or normalised;
 * null for a user without a usable id), the user identifier and the role names the user object reports (no role
 * hierarchy). The user object itself ({@see user()}) is **not** a property: it lives in an internal {@see \WeakMap}
 * keyed by this object, so a dump, `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of the VO never shows
 * a password hash or another field of the user, and a `clone` or an unserialized copy has no user object.
 *
 * A reference built by your own code with an id and no user object ({@see self::ofId()}) is your statement that the id
 * is a vault user id (`nowo_vault.user_class` id space): the vault then works with that id only (the same rules as the
 * `getId()` of a `user_class` instance, including the strict id-mapping rules).
 *
 * Built per call and never stored by the bundle (FrankenPHP worker mode, kernel reset false).
 */
final readonly class VaultUserRef
{
    /** Bag of the user object. */
    private const USER = 'user';

    /** @var list<string> */
    public array $roles;

    /**
     * @param string|null $id the vault user id (null: the user has no usable id); an empty or blank id is refused
     * @param string $identifier the user identifier (login), '' when unknown
     * @param array<int, string> $roles the role names the user reports (no hierarchy)
     * @param object|null $user the host's user object (hidden, see the class comment)
     *
     * @throws InvalidArgumentException when the id is empty or blank
     */
    public function __construct(
        public ?string $id,
        public string $identifier = '',
        array $roles = [],
        ?object $user = null,
    ) {
        if ($id !== null && trim($id) === '') {
            throw new InvalidArgumentException('A vault user id cannot be empty; pass null for a user without an id.');
        }

        $kept = [];
        foreach ($roles as $role) {
            if (is_string($role)) {
                $kept[] = $role;
            }
        }
        $this->roles = array_values($kept);
        HiddenValues::put($this, [self::USER => [self::USER => $user]]);
    }

    /**
     * A reference known only by its id (no user object): e.g. from your own user directory or a message payload.
     *
     * @param array<int, string> $roles
     *
     * @throws InvalidArgumentException when the id is empty or blank
     */
    public static function ofId(string $id, string $identifier = '', array $roles = []): self
    {
        return new self($id, $identifier, $roles);
    }

    /**
     * The host's user object, or null (a reference built from an id, a clone or an unserialized copy).
     */
    public function user(): ?object
    {
        $user = HiddenValues::get($this, self::USER, self::USER);

        return is_object($user) ? $user : null;
    }

    /**
     * Whether the reference has a vault user id.
     */
    public function hasId(): bool
    {
        return $this->id !== null;
    }

    /**
     * Whether the user reports this role name (no role hierarchy).
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Whether both references have the same id, byte for byte (false when either has no id).
     */
    public function isSameUserAs(self $other): bool
    {
        return $this->id !== null && $this->id === $other->id;
    }
}
