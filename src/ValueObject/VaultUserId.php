<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\ValueObject;

use InvalidArgumentException;
use Nowo\VaultBundle\Support\UserIdResolver;
use Stringable;

use function is_string;
use function trim;

/**
 * A host user known only by its id (1.17.0): what the vault entities return for a creator, an audit creator or a token
 * user when `nowo_vault.user_mapping` is `id` (the column holds the id, there is no relation to the host user table).
 *
 * It exposes `getId()` like a host user, so ownership checks, owner keys and events work the same in both mappings.
 */
final readonly class VaultUserId implements Stringable
{
    /**
     * @throws InvalidArgumentException when the id is empty
     */
    public function __construct(
        private string $id,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A vault user id cannot be empty.');
        }
    }

    /**
     * The id as stored (the string form of the host user's `getId()`).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Same as {@see self::getId()}.
     */
    public function __toString(): string
    {
        return $this->id;
    }

    /**
     * The object the entities expose for a stored user column: a {@see self} for an id (id mapping), the related
     * object unchanged (relation mapping), null for null.
     *
     * @return ($stored is null ? null : object)
     *
     * @internal used by the vault entities
     */
    public static function wrap(object|string|null $stored): ?object
    {
        return is_string($stored) ? new self($stored) : $stored;
    }

    /**
     * The id of a stored user column: the string itself (id mapping) or the related object's `getId()` (relation
     * mapping), null when unknown.
     *
     * @internal used by the vault entities
     */
    public static function idOf(object|string|null $stored): ?string
    {
        if ($stored === null || is_string($stored)) {
            return $stored;
        }

        return UserIdResolver::getId($stored);
    }
}
