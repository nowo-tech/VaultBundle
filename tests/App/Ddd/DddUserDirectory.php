<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ddd;

use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

use function str_starts_with;
use function substr;

/**
 * The DDD host's user directory (1.17.0 tests): the security user provider and the vault user locator port, backed
 * by a fixed list (no Doctrine). Users of {@see OtherDomainUser} sign in with an `admin-` prefix; `id:<id>` signs in a
 * {@see DddUser} with that exact id.
 *
 * @implements UserProviderInterface<UserInterface>
 */
final class DddUserDirectory implements UserProviderInterface, VaultUserLocatorInterface
{
    public const ALICE = '0b5f2c1e-1111-4a1a-8a1a-000000000001';
    public const BOB   = '0b5f2c1e-2222-4a1a-8a1a-000000000002';

    public function find(string $id): ?UserInterface
    {
        foreach (self::users() as $user) {
            if ($user->getId() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === DddUser::class || $class === OtherDomainUser::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ($identifier === 'admin@example.com') {
            return new OtherDomainUser(self::ALICE, $identifier);
        }
        // `id:<id>` signs in a user with exactly that id (database tests: case and invisible-character variants).
        if (str_starts_with($identifier, 'id:')) {
            return new DddUser(substr($identifier, 3), $identifier);
        }

        foreach (self::users() as $user) {
            if ($user->getUserIdentifier() === $identifier) {
                return $user;
            }
        }

        throw new UserNotFoundException();
    }

    /**
     * @return list<DddUser>
     */
    public static function users(): array
    {
        return [new DddUser(self::ALICE, 'alice@example.com'), new DddUser(self::BOB, 'bob@example.com')];
    }
}
