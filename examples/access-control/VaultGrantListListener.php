<?php

declare(strict_types=1);

namespace App\VaultExamples\AccessControl;

use Nowo\VaultBundle\Dto\VaultGranteeChoice;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantListQueryEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Limits who can receive access when sharing vault items or folders.
 *
 * Team and group of people are the same concept ({@see GranteeType::Team}).
 */
#[AsEventListener(event: VaultEvents::GRANT_LIST_QUERY)]
final class VaultGrantListListener
{
    public function __construct(
        private readonly VaultCollaboratorDirectory $directory,
    ) {
    }

    public function __invoke(VaultGrantListQueryEvent $event): void
    {
        foreach ($this->directory->listUsersFor($event->getGrantedBy(), $event->getResourceType(), $event->getResourceId()) as $user) {
            $event->addGrantee(new VaultGranteeChoice(GranteeType::User, $user->id, $user->label));
        }

        foreach ($this->directory->listTeamsFor($event->getGrantedBy(), $event->getResourceType(), $event->getResourceId()) as $team) {
            $event->addGrantee(new VaultGranteeChoice(GranteeType::Team, $team->id, $team->label));
        }
    }
}

/**
 * Example directory — implement with your user/team repositories.
 */
interface VaultCollaboratorDirectory
{
    /**
     * @return list<object{id: string, label: string}>
     */
    public function listUsersFor(object $grantedBy, VaultResourceType $resourceType, string $resourceId): array;

    /**
     * Teams and groups of people use the same ids as {@see GranteeType::Team}.
     *
     * @return list<object{id: string, label: string}>
     */
    public function listTeamsFor(object $grantedBy, VaultResourceType $resourceType, string $resourceId): array;
}
