<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultRefUser;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How the bundle loads a user by id (1.19.0): the host's {@see VaultUserDirectoryInterface} (an answer for exactly the
 * requested id; without a user object it becomes the vault's stand-in carrying the directory's identifier and roles),
 * or the deprecated {@see VaultUserLocatorInterface}, exactly as in 1.18. Never both: the build picks one.
 *
 * @internal
 */
final readonly class VaultUserLocatorGate implements VaultUserLocatorInterface
{
    public function __construct(
        private string $mode,
        private ?VaultUserLocatorInterface $legacy = null,
        private ?VaultUserDirectoryInterface $policy = null,
    ) {
        VaultPortMode::assert($mode === VaultPortMode::BOTH ? '' : $mode, $legacy, $policy, 'user locator');
    }

    public function find(string $id): ?UserInterface
    {
        if ($this->legacy instanceof VaultUserLocatorInterface && $this->mode === VaultPortMode::LEGACY) {
            return $this->legacy->find($id);
        }

        /** @var VaultUserDirectoryInterface $directory asserted in the constructor */
        $directory = $this->policy;
        $ref       = $directory->findUser($id);
        // Audit NJ-1: an answer for another id (byte for byte) is refused.
        if (!$ref instanceof VaultUserRef || $ref->id !== $id) {
            return null;
        }

        $user = VaultUserRefs::toUser($ref);
        if ($user instanceof UserInterface) {
            return UserIdResolver::getId($user) === $id ? $user : null;
        }

        return VaultRefUser::fromDirectory($ref);
    }
}
