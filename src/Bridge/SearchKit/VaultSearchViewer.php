<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Bridge\SearchKit;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The viewer of one search (1.21.0): the signed-in vault user, its vault user id and the value bound to the user
 * columns (a `user_class` object with the relation mapping, the id string with the id mapping). Built per call, never
 * kept.
 *
 * @internal
 */
final readonly class VaultSearchViewer
{
    /**
     * @param UserInterface $user the signed-in user
     * @param string $id its vault user id (what SearchKit sees as the tenant)
     * @param object|string $reference the value compared with `creator_id`
     */
    public function __construct(
        public UserInterface $user,
        public string $id,
        public object|string $reference,
    ) {
    }
}
