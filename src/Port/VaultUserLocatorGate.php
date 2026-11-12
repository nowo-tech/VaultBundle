<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Port;

use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * How the bundle loads a user by id (1.19.0): the host's {@see VaultUserDirectoryInterface} (a reference without a user
 * object becomes the vault's stand-in for that id), or the deprecated {@see VaultUserLocatorInterface}, exactly as in
 * 1.18. Never both: the build picks one.
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

        return $ref instanceof VaultUserRef ? VaultUserRefs::toUser($ref) : null;
    }
}
