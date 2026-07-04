<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Stub;

use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class TestTeamMembershipResolver implements VaultTeamMembershipResolverInterface
{
    /** @param array<string, list<string>> $teamsByUserId */
    public function __construct(
        private array $teamsByUserId = [],
    ) {
    }

    public function getTeamIdsForUser(UserInterface $user): array
    {
        if (!method_exists($user, 'getId')) {
            return [];
        }

        $id = (string) $user->getId();

        return $this->teamsByUserId[$id] ?? [];
    }
}
