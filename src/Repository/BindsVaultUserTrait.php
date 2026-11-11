<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Repository;

use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Binds a host user compared with a user column (1.17.0): the `user_class` object (relation mapping) or its string id
 * (id mapping), through the user reference resolver. Without a resolver the user is bound as given (pre-1.17 wiring).
 *
 * Requires the using class to declare `?VaultUserReferenceResolverInterface $users`.
 *
 * @internal
 */
trait BindsVaultUserTrait
{
    /**
     * @throws AccessDeniedException when the resolver refuses the user
     */
    private function userParameter(object $user): object|string
    {
        return $this->users instanceof VaultUserReferenceResolverInterface ? $this->users->reference($user) : $user;
    }
}
