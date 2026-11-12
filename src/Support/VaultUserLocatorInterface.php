<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Support;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Port (1.17.0): loads a host user by id, e.g. to check that a user grantee belongs to a scope, or to resolve the user
 * of a browser extension token with `nowo_vault.user_mapping: id`.
 *
 * The default ({@see VaultUserLocator}) loads `nowo_vault.user_class` through Doctrine. A host whose users are not
 * Doctrine entities (or live in another store) aliases this interface to its own service.
 *
 * @deprecated since 1.19.0, implement {@see VaultUserDirectoryInterface} instead (framework-free, REQ-ARCH-001 rule 3); removed in 2.0.
 */
interface VaultUserLocatorInterface
{
    /**
     * The user with this id, or null (unknown id, or an id of the wrong type).
     */
    public function find(string $id): ?UserInterface;
}
