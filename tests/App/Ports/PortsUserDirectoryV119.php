<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

use function array_search;
use function is_string;

/**
 * The same DDD users through the 1.19 directory: references by id only, no Symfony user object.
 */
final readonly class PortsUserDirectoryV119 implements VaultUserDirectoryInterface
{
    public function findUser(string $id): ?VaultUserRef
    {
        $email = array_search($id, PortsUserDirectory::USERS, true);

        return is_string($email) ? VaultUserRef::ofId($id, $email, ['ROLE_USER']) : null;
    }
}
