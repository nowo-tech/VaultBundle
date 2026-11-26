<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\App\Ports;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\VaultBundle\Support\VaultUserDirectoryInterface;
use Nowo\VaultBundle\Tests\App\Entity\User;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

use function ctype_digit;

/**
 * 2.5.0 (audit ZCM-L3): the relation-mapping users through a directory that answers references by id only (no user
 * object), as a DDD host with Doctrine users may do. The vault must bind getReference(user_class, id), never the
 * stand-in object it builds from such an answer.
 */
final readonly class RelationRefsUserDirectory implements VaultUserDirectoryInterface
{
    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function findUser(string $id): ?VaultUserRef
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $user = $this->doctrine->getManager()->find(User::class, (int) $id);

        return $user instanceof User && (string) $user->getId() === $id ? VaultUserRef::ofId($id, $user->getUserIdentifier(), ['ROLE_USER']) : null;
    }
}
