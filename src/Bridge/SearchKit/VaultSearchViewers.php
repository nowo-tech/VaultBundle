<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Bridge\SearchKit;

use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

/**
 * Resolves the viewer of a search (1.21.0) from the token storage on every call (FrankenPHP worker mode: nothing is
 * kept). Fail closed: no viewer unless the signed-in user has exactly the query's user identifier (`===`), the vault
 * accepts it (`user_class` or `accepted_user_classes`, so Admin#5 is never Customer#5) and its id passes the id rules of
 * `user_mapping` (with `id`: no blank, padded, invisible or over-long id). With the id mapping the bound value must be
 * that very id string.
 *
 * @internal
 */
final readonly class VaultSearchViewers
{
    /**
     * Keeps the token storage and the vault's user reference resolver.
     */
    public function __construct(
        private VaultUserReferenceResolverInterface $users,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * Whether the vault stores user ids in string columns (`user_mapping: id`) rather than relations.
     */
    public function mapsById(): bool
    {
        return $this->users->mapsById();
    }

    /**
     * The viewer whose user identifier is `$userIdentifier`, or null.
     */
    public function forIdentifier(string $userIdentifier): ?VaultSearchViewer
    {
        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface || $user->getUserIdentifier() !== $userIdentifier || !$this->users->isAccepted($user)) {
            return null;
        }
        $id = UserIdResolver::getId($user);
        if ($id === null) {
            return null;
        }

        try {
            $reference = $this->users->reference($user);
        } catch (AccessDeniedException) {
            return null;
        }
        if ($this->users->mapsById() && (!is_string($reference) || $reference !== $id)) {
            return null;
        }

        return new VaultSearchViewer($user, $id, $reference);
    }

    /**
     * The viewer of `$userIdentifier` when its vault user id is exactly `$tenant`, or null.
     */
    public function forTenant(int|string $tenant, string $userIdentifier): ?VaultSearchViewer
    {
        $viewer = $this->forIdentifier($userIdentifier);

        return $viewer instanceof VaultSearchViewer && is_string($tenant) && $viewer->id === $tenant ? $viewer : null;
    }
}
