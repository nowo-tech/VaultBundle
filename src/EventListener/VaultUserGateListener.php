<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\EventListener;

use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function get_debug_type;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Refuses the vault pages to a signed-in user the vault does not accept (1.17.0): neither a `nowo_vault.user_class`
 * instance nor an instance of a class listed in `nowo_vault.accepted_user_classes` (with a usable `getId()`).
 *
 * Ownership is compared by id, so without this gate a user of another identity domain (an Admin#5 next to a
 * Customer#5) would be treated as the owner of the other domain's personal vault. Standard hosts (the security user is
 * the `user_class` entity) are not affected. Stateless: safe in a kept-alive worker.
 */
final readonly class VaultUserGateListener
{
    private const CONTROLLER_NAMESPACE = 'Nowo\\VaultBundle\\Controller\\';

    public function __construct(
        private VaultUserReferenceResolverInterface $users,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    /**
     * @throws AccessDeniedException when the signed-in user is not a vault user
     */
    public function onKernelController(ControllerEvent $event): void
    {
        $controller = $event->getRequest()->attributes->get('_controller');
        if (!is_string($controller) || !str_starts_with($controller, self::CONTROLLER_NAMESPACE)) {
            return;
        }

        $user = $this->tokenStorage?->getToken()?->getUser();
        if (!$user instanceof UserInterface || $this->users->isAccepted($user)) {
            return;
        }

        throw new AccessDeniedException(sprintf('nowo_vault: a %s is not a vault user.', get_debug_type($user)));
    }
}
