<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Doctrine;

/**
 * Values of `nowo_vault.user_mapping` (1.17.0): how the vault entities store the host user.
 */
final class VaultUserMapping
{
    /** Doctrine relations to `user_class` (foreign keys to the host users table). The default. */
    public const RELATION = 'relation';

    /** The host user id in a string column: no relation, no foreign key, `user_class` need not be a Doctrine entity. */
    public const ID = 'id';

    /**
     * Every supported value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::RELATION, self::ID];
    }
}
