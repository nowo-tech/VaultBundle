<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Port (1.17.0): turns the user handed to the vault (the security-token user, a grantee, an erasure subject) into the
 * value stored in, and compared with, the creator / audit creator / token user columns.
 *
 * The default ({@see DoctrineVaultUserReferenceResolver}) follows `nowo_vault.user_mapping`:
 * - `relation` (default): a `user_class` instance unchanged; an instance of a class listed in
 *   `nowo_vault.accepted_user_classes` becomes a Doctrine reference of `user_class` with its `getId()`;
 * - `id`: the string form of the user's `getId()` (no relation to the host user table).
 *
 * Any other user is refused (fail closed). Alias the interface to your own service when the ids differ.
 */
interface VaultUserReferenceResolverInterface
{
    /**
     * Whether the vault stores host users by id (`nowo_vault.user_mapping: id`).
     */
    public function mapsById(): bool;

    /**
     * Whether the vault accepts this user as a creator / owner.
     */
    public function isAccepted(object $user): bool;

    /**
     * The value to store in a user column, or to bind when comparing one: a `user_class` object (relation mapping)
     * or the user id as a string (id mapping).
     *
     * @throws AccessDeniedException when the user is not accepted or has no usable id
     */
    public function reference(object $user): object|string;
}
