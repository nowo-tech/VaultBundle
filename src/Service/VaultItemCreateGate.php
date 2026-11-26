<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use Doctrine\DBAL\LockMode;
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
 * The event's lazy {@see VaultItemCreateCheckEvent::itemCount()} reads the ids of the owner's stored items (trash
 * included) with `SELECT … FOR UPDATE` when a transaction is open (always inside the vault's write unit): a second
 * creation for the same owner waits until the first commits, then counts it. Residual (docs/CONCURRENCY.md): an owner
 * with no stored item has no row to lock, so on PostgreSQL two first creations can both pass a limit of 1; lock a row
 * of your own (the account or plan row) in the listener when that matters. Owner ids compare exactly (REQ-SEC-006):
 * with the id mapping the stored creator is compared with `===` too, and the scope key always is.
 *
 * Stateless (FrankenPHP worker mode, kernel reset off).
 *
 * @internal
 */
final readonly class VaultItemCreateGate
{
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
            fn (): int => $scope !== null ? $this->countForScope($scope) : $this->countForUser($actor),
        );
        $this->eventDispatcher->dispatch($event, VaultEvents::ITEM_CREATE_CHECK);

        $reason = $event->getReason();
        if (is_string($reason)) {
            throw new VaultItemCreateDeniedException($reason);
        }
    }

    private function countForScope(string $scope): int
    {
        $em   = $this->em();
        $rows = $this->locked($em, $em->createQuery('SELECT i.id AS id, i.ownerScope AS owner FROM ' . VaultItem::class . ' i WHERE i.ownerScope = :scope ORDER BY i.id ASC')
            ->setParameter('scope', $scope));

        $count = 0;
        foreach ($rows as $row) {
            if (($row['owner'] ?? null) === $scope) {
                ++$count;
            }
        }

        return $count;
    }

    private function countForUser(UserInterface $user): int
    {
        $em     = $this->em();
        $strict = $this->users?->mapsById() === true;
        $userId = UserIdResolver::getId($user);
        $query  = $em->createQuery('SELECT i.id AS id' . ($strict ? ', i.creator AS owner' : '') . ' FROM ' . VaultItem::class . ' i WHERE i.creator = :user AND i.ownerScope IS NULL ORDER BY i.id ASC')
            ->setParameter('user', $this->users instanceof VaultUserReferenceResolverInterface ? $this->users->reference($user) : $user);
        $rows = $this->locked($em, $query);
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
     * Inside a transaction: a first `SELECT … FOR UPDATE` waits for the creations in flight for this owner (their rows
     * are locked by the same read, or inserted under it), then a second one reads what they committed. One read would
     * not be enough: on PostgreSQL a statement that waited still answers from its own start, and on MySQL / MariaDB
     * only a locking read sees rows committed after the transaction's first plain read.
     *
     * @return list<array<string, mixed>>
     */
    private function locked(EntityManagerInterface $em, Query $query): array
    {
        if ($em->getConnection()->isTransactionActive()) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
            $query->getArrayResult();
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getArrayResult();

        return $rows;
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
