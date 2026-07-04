<?php

declare(strict_types=1);

namespace App\VaultExamples\AccessControl;

use App\Repository\ShareGrantRepository;
use Nowo\VaultBundle\Event\ShareAccessAction;
use Nowo\VaultBundle\Event\ShareAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Grants individual access to specific shares (collaborators, delegated viewers).
 *
 * Store grants in your database, e.g. (share_id, user_id, allowed_actions[]).
 */
#[AsEventListener(event: VaultEvents::SHARE_ACCESS_CHECK)]
final class IndividualShareGrantListener
{
    public function __construct(
        private readonly ShareGrantRepository $grants,
    ) {
    }

    public function __invoke(ShareAccessCheckEvent $event): void
    {
        if ($event->isGranted()) {
            return;
        }

        $shareId = $event->getShare()->getId();
        $userId  = (string) $event->getUser()->getUserIdentifier();

        if (!$this->grants->isGranted($shareId, $userId, $event->getAction())) {
            return;
        }

        $event->grant();
    }
}

/**
 * Example grant store — implement with Doctrine or your persistence layer.
 */
interface ShareGrantRepository
{
    public function isGranted(string $shareId, string $userId, ShareAccessAction $action): bool;
}
