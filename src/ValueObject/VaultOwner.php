<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\ValueObject;

use Nowo\VaultBundle\Exception\VaultOwnerException;
use Nowo\VaultBundle\Support\UserIdResolver;

use function preg_match;

/**
 * Owner of a vault item, folder or tag (1.16.0): a user (the personal vault) or a host scope key (a project or
 * organization vault). Exactly one of the two is set.
 */
final readonly class VaultOwner
{
    /** Pattern of a scope key, e.g. `project-<uuid>` or `org-<uuid>` (lowercase, 1 to 128 characters). */
    public const SCOPE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,127}$/D';

    /** Route requirement of the `{scope}` placeholder (same rule as {@see self::SCOPE_PATTERN}). */
    public const SCOPE_REQUIREMENT = '[a-z0-9][a-z0-9_-]{0,127}';

    private function __construct(
        private ?object $user,
        private ?string $scope,
    ) {
    }

    /**
     * The personal vault of a user.
     */
    public static function user(object $user): self
    {
        return new self($user, null);
    }

    /**
     * A host scope.
     *
     * @throws VaultOwnerException when the key does not match {@see self::SCOPE_PATTERN}
     */
    public static function scope(string $key): self
    {
        if (!self::isValidScope($key)) {
            throw new VaultOwnerException('Invalid vault scope key.');
        }

        return new self(null, $key);
    }

    /**
     * The owner of a row from its two owner columns (`creator_id`, `owner_scope`): exactly one must be set.
     *
     * @throws VaultOwnerException when both or neither are set, or the scope key is invalid
     */
    public static function fromColumns(?object $creator, ?string $ownerScope): self
    {
        if ($creator !== null && $ownerScope === null) {
            return self::user($creator);
        }

        if ($creator === null && $ownerScope !== null) {
            return self::scope($ownerScope);
        }

        throw new VaultOwnerException('A vault row is owned by exactly one user or one scope.');
    }

    /**
     * Whether the key is a valid scope key.
     */
    public static function isValidScope(string $key): bool
    {
        return preg_match(self::SCOPE_PATTERN, $key) === 1;
    }

    /**
     * Whether the owner is a scope (not a user).
     */
    public function isScope(): bool
    {
        return $this->scope !== null;
    }

    /**
     * The owning user, or null for a scope.
     */
    public function getUser(): ?object
    {
        return $this->user;
    }

    /**
     * The scope key, or null for a user.
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Whether this owner is the given user (same id). Always false for a scope.
     */
    public function isUser(object $user): bool
    {
        return $this->user !== null && UserIdResolver::isSameUser($this->user, $user);
    }

    /**
     * Stable key for events and logs: `user:<id>` or `scope:<key>` (no title, no personal data beyond the id).
     */
    public function key(): string
    {
        if ($this->scope !== null) {
            return 'scope:' . $this->scope;
        }

        return 'user:' . ($this->user !== null ? (UserIdResolver::getId($this->user) ?? '') : '');
    }

    /**
     * Whether both owners are the same user (by id) or the same scope.
     */
    public function equals(self $other): bool
    {
        if ($this->scope !== null || $other->scope !== null) {
            return $this->scope === $other->scope;
        }

        return $this->user !== null && $other->user !== null && UserIdResolver::isSameUser($this->user, $other->user);
    }
}
