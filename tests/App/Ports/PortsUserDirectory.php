<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Users of a DDD host for the 1.19 port tests (id mapping, UUID ids, no Doctrine): the security user provider and the
 * deprecated user locator port.
 *
 * @implements UserProviderInterface<UserInterface>
 */
final class PortsUserDirectory implements UserProviderInterface, VaultUserLocatorInterface
{
    /** @var array<string, string> e-mail => id */
    public const USERS = [
        'alice@example.com' => '7d1e0000-0000-4000-8000-00000000000a',
        'bob@example.com'   => '7d1e0000-0000-4000-8000-00000000000b',
        'carol@example.com' => '7d1e0000-0000-4000-8000-00000000000c',
        'dave@example.com'  => '7d1e0000-0000-4000-8000-00000000000d',
        'erin@example.com'  => '7d1e0000-0000-4000-8000-00000000000e',
        'frank@example.com' => '7d1e0000-0000-4000-8000-00000000000f',
    ];

    public function find(string $id): ?UserInterface
    {
        foreach (self::USERS as $email => $userId) {
            if ($userId === $id) {
                return new DddUser($userId, $email);
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
        return $class === DddUser::class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if (!isset(self::USERS[$identifier])) {
            throw new UserNotFoundException();
        }

        return new DddUser(self::USERS[$identifier], $identifier);
    }
}
