<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Internal;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;

/**
 * Loads one vault row by exact id for a 2.1 command (audit YF-L1). Inside a write unit the row is read with
 * `SELECT … FOR UPDATE` and the managed entity refreshed from it: a concurrent write waits, then this call sees the
 * committed row, and a row deleted meanwhile is **not found** (an `EntityManager::refresh()` of a vanished row does
 * nothing in ORM 3). Outside a transaction it is a plain read. Stateless.
 *
 * @internal
 */
final class VaultRowLock
{
    /**
     * The row with exactly this id (byte for byte), or null.
     *
     * @template T of VaultItem|VaultFolder
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function find(EntityManagerInterface $entityManager, string $class, string $id): ?object
    {
        if ($id === '') {
            return null;
        }

        $query = $entityManager->createQueryBuilder()
            ->select('r')
            ->from($class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true);
        if ($entityManager->getConnection()->isTransactionActive()) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        foreach ($query->getResult() as $row) {
            // Exact id (REQ-SEC-006): a case or padding variant the database matched is not this row.
            if ($row instanceof $class && $row->getId() === $id) {
                return $row;
            }
        }

        return null;
    }
}
