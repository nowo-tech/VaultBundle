<?php

declare(strict_types=1);

namespace App\VaultExamples\AccessControl;

use App\Entity\User;
use App\Repository\ShareTeamRepository;
use Nowo\VaultBundle\Event\ShareAccessAction;
use Nowo\VaultBundle\Event\ShareAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grants manage access when the share creator belongs to the same team as the viewer.
 *
 * Copy into your application and wire services (see services.example.yaml).
 * Requires your User entity to expose getTeamId() and a ShareTeamRepository (example below).
 */
#[AsEventListener(event: VaultEvents::SHARE_ACCESS_CHECK)]
final class TeamShareAccessListener
{
    public function __construct(
        private readonly ShareTeamRepository $teams,
    ) {
    }

    public function __invoke(ShareAccessCheckEvent $event): void
    {
        if ($event->isGranted()) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $creator = $event->getShare()->getCreator();
        if (!$creator instanceof User) {
            return;
        }

        $viewerTeam  = $user->getTeamId();
        $creatorTeam = $creator->getTeamId();

        if ($viewerTeam === null || $creatorTeam === null || $viewerTeam !== $creatorTeam) {
            return;
        }

        if (!$this->teams->viewerCanAccessTeamShares($user, $viewerTeam)) {
            return;
        }

        if ($event->getAction() === ShareAccessAction::Delete && !$this->teams->canDeleteTeamShares($user)) {
            return;
        }

        $event->grant();
    }
}

/**
 * Example repository — replace with your persistence layer.
 */
interface ShareTeamRepository
{
    public function viewerCanAccessTeamShares(UserInterface $viewer, string $teamId): bool;

    public function canDeleteTeamShares(UserInterface $viewer): bool;

    /**
     * @return list<object> team member user entities whose shares should appear in the list
     */
    public function listTeamMemberCreators(UserInterface $viewer, string $teamId): array;
}
