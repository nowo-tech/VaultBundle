<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Worker;

use Doctrine\DBAL\Connection;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultScopeAccessResolverInterface;
use Nowo\VaultBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_array;
use function is_string;

/**
 * Host scope resolver of the worker kernel: membership lives in the `test_scope_member` table, so a test can revoke it
 * with plain SQL behind the bundle's back (as another request, worker or admin tool would). Counts its calls.
 */
final class DbalScopeAccessResolver implements VaultScopeAccessResolverInterface
{
    public const TABLE = 'test_scope_member';

    public static int $calls = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function resolve(UserInterface $user, string $scope): ?VaultScopeAccess
    {
        ++self::$calls;
        $row = $this->connection->fetchAssociative(
            'SELECT permission, can_create FROM ' . self::TABLE . ' WHERE user_id = ? AND scope = ?',
            [(string) UserIdResolver::getId($user), $scope],
        );
        if (!is_array($row) || !is_string($row['permission'])) {
            return null;
        }

        return new VaultScopeAccess(VaultPermission::from($row['permission']), create: (bool) $row['can_create']);
    }

    /**
     * Drops and recreates the membership table (fresh fixture).
     */
    public static function createTable(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS ' . self::TABLE);
        $connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (user_id VARCHAR(64) NOT NULL, scope VARCHAR(64) NOT NULL, permission VARCHAR(16) NOT NULL, can_create INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (user_id, scope))');
    }
}
