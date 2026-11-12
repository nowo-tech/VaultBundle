<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Security\VaultScopeAccess;

use function in_array;

/**
 * The host's own access rules for the 1.19 port tests: the same answers whatever interface they are served through
 * (deprecated, 1.19 or both). Keyed by user identifier (e-mail), so they hold in the relation (integer ids) and id
 * (UUID ids) mappings alike.
 */
final class HostRules
{
    /** @var array<string, list<string>> */
    public const TEAMS = ['carol@example.com' => ['team-1'], 'erin@example.com' => ['team-2']];

    public static function allows(string $feature, ?string $identifier, array $roles): bool
    {
        if ($identifier === null || !in_array('ROLE_USER', $roles, true)) {
            return false;
        }

        return match ($feature) {
            'create' => $identifier !== 'dave@example.com',
            'revoke' => $identifier !== 'frank@example.com',
            default  => true,
        };
    }

    /**
     * @return list<string>
     */
    public static function teams(?string $identifier): array
    {
        return self::TEAMS[$identifier ?? ''] ?? [];
    }

    public static function scope(?string $identifier, string $scope): ?VaultScopeAccess
    {
        return match ([$identifier, $scope]) {
            ['alice@example.com', 'org-1'] => new VaultScopeAccess(VaultPermission::Admin, true, true, true),
            ['dave@example.com', 'org-1']  => new VaultScopeAccess(VaultPermission::Read, true),
            ['erin@example.com', 'org-1']  => new VaultScopeAccess(null, true),
            default                        => null,
        };
    }
}
