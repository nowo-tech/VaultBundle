<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Stub;

use Nowo\VaultBundle\Security\VaultTeamMembershipProviderInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

final readonly class TestTeamMembershipResolver implements VaultTeamMembershipProviderInterface
{
    /** @param array<string, list<string>> $teamsByUserId */
    public function __construct(
        private array $teamsByUserId = [],
    ) {
    }

    public function teamIdsOf(VaultUserRef $user): array
    {
        return $user->id === null ? [] : $this->teamsByUserId[$user->id] ?? [];
    }
}
