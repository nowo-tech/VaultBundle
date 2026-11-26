<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultItemCreateCheckEvent;
use Nowo\VaultBundle\Exception\VaultItemCreateDeniedException;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\ValueObject\VaultOwner;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function is_string;

/**
 * Asks ITEM_CREATE_CHECK before a new item is written (2.5.0, Beacon spec 185 FR-020). Called by
 * {@see VaultItemCreator} on every creation path, so the pages and the host commands honour the same answer.
 *
 * The event's lazy {@see VaultItemCreateCheckEvent::itemCount()} counts the owner's stored items (trash included)
 * serialised per owner when a transaction is open (always inside the vault's write unit): a second creation for the
 * same owner waits until the first commits, then counts it ({@see self::locked()}: an advisory lock on PostgreSQL,
 * row and gap locks elsewhere). Owner ids compare exactly (REQ-SEC-006):
 * with the id mapping the stored creator is compared with `===` too, and the scope key always is.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off).
 *
 * @internal
 */
final readonly class VaultItemCreateGate
{
    /** Namespace of the PostgreSQL advisory lock key (the owner key follows). */
    public const LOCK_PREFIX = 'nowo_vault.item_create:';

    public function __construct(
        private ManagerRegistry $registry,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?VaultUserReferenceResolverInterface $users = null,
        private ?string $managerName = null,
    ) {
    }

    /**
     * @throws VaultItemCreateDeniedException when a listener refuses the item
     */
    public function check(UserInterface $actor, VaultOwner $owner, VaultItemType $type): void
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return;
        }

        $scope   = $owner->getScope();
        $actorId = UserIdResolver::getId($actor);
        $event   = new VaultItemCreateCheckEvent(
            $owner->key(),
            $scope === null ? $actorId : null,
            $scope,
            $actorId,
            $type,
            fn (): int => $scope !== null ? $this->countForScope($scope, $owner->key()) : $this->countForUser($actor, $owner->key()),
        );
        $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_CREATE_CHECK);

        $reason = $event->getReason();
        if (is_string($reason)) {
            throw new VaultItemCreateDeniedException($reason);
        }
    }

    private function countForScope(string $scope, string $ownerKey): int
    {
        $em   = $this->em();
        $rows = $this->locked($em, $em->createQuery('SELECT i.id AS id, i.ownerScope AS owner FROM ' . VaultItem::class . ' i WHERE i.ownerScope = :scope ORDER BY i.id ASC')
            ->setParameter('scope', $scope), $ownerKey);

        $count = 0;
        foreach ($rows as $row) {
            if (($row['owner'] ?? null) === $scope) {
                ++$count;
            }
        }

        return $count;
    }

    private function countForUser(UserInterface $user, string $ownerKey): int
    {
        $em     = $this->em();
        $strict = $this->users?->mapsById() === true;
        $userId = UserIdResolver::getId($user);
        $query  = $em->createQuery('SELECT i.id AS id' . ($strict ? ', i.creator AS owner' : '') . ' FROM ' . VaultItem::class . ' i WHERE i.creator = :user AND i.ownerScope IS NULL ORDER BY i.id ASC')
            ->setParameter('user', $this->users instanceof VaultUserReferenceResolverInterface ? $this->users->reference($user) : $user);
        $rows = $this->locked($em, $query, $ownerKey);
        if (!$strict) {
            return count($rows);
        }

        $count = 0;
        foreach ($rows as $row) {
            if (($row['owner'] ?? null) === $userId) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Inside a transaction (always inside the vault's write unit) the count is serialised per owner:
     *
     * - PostgreSQL (2.5.0, audit ZCM-L4): a transaction-scoped advisory lock on a stable hash of the owner key
     *   (`pg_advisory_xact_lock(hashtextextended(…, 0))`, released at commit or rollback), then a plain read, which sees
     *   what the previous holder committed (READ COMMITTED). It also covers an owner with no stored item, and it locks no
     *   item row (edits are never blocked by a count);
     * - other platforms: a first `SELECT … FOR UPDATE` waits for the creations in flight for this owner (their rows are
     *   locked by the same read, or inserted under it, gap locks included on MySQL / MariaDB, where two first creations
     *   deadlock instead and one answers 409 `CONFLICT_001`), then a second one reads what they committed (only a
     *   locking read sees rows committed after the transaction's first plain read).
     *
     * @return list<array<string, mixed>>
     */
    private function locked(EntityManagerInterface $em, Query $query, string $ownerKey): array
    {
        $connection = $em->getConnection();
        if ($connection->isTransactionActive() && !self::ownerLock($connection, $ownerKey)) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
            $query->getArrayResult();
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getArrayResult();

        return $rows;
    }

    /**
     * PostgreSQL: waits for the transaction-scoped advisory lock of the owner (released at commit or rollback) and
     * returns true; false on other platforms (they lock rows instead).
     *
     * @internal
     */
    public static function ownerLock(Connection $connection, string $ownerKey): bool
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return false;
        }

        $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::LOCK_PREFIX . $ownerKey])->free();

        return true;
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The item create check needs a Doctrine ORM entity manager.');
        }

        return $em;
    }
}
