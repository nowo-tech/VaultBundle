<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Doctrine\VaultExtensionTokenTable;
use Nowo\VaultBundle\Doctrine\VaultWriteUnit;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultExtensionTokenRevokedEvent;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Support\UserIdResolver;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Support\VaultUserRefResolver;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultExtensionTokenView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function count;
use function in_array;
use function is_scalar;
use function strlen;
use function usort;

/**
 * Per-user list and revocation of browser extension tokens (2.5.0, {@see VaultExtensionTokenCommandsInterface}).
 *
 * The user's token ids come from an exact read (the mapped repository's byte-for-byte owner filter, or the raw token
 * table while the entity is not mapped, REQ-SEC-006); only those ids are deleted, by id, in one vault write unit (a
 * savepoint inside the application's transaction). A token deleted meanwhile (logout, purge, another revoke) counts as
 * not revoked and emits nothing. The event leaves after the commit (deferred dispatcher). Never reads or returns a
 * hash. Stateless (FrankenPHP worker mode, kernel reset off).
 */
final readonly class VaultExtensionTokenCommands implements VaultExtensionTokenCommandsInterface
{
    /** Token ids are UUIDs: anything longer is never one. */
    private const MAX_ID_LENGTH = 36;

    public function __construct(
        private ManagerRegistry $registry,
        private VaultUserRefResolver $refs,
        private ?VaultWriteUnit $writeUnit = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?VaultUserReferenceResolverInterface $users = null,
        private ?VaultExtensionTokenTable $tokenTable = null,
        private ?ClockInterface $clock = null,
        private ?string $managerName = null,
    ) {
    }

    public function listForUser(VaultUserRef $user): array
    {
        return $this->tokens(...$this->user($user));
    }

    public function revoke(VaultUserRef $user, string $tokenId): bool
    {
        [$owner, $userId] = $this->user($user);
        if ($tokenId === '' || strlen($tokenId) > self::MAX_ID_LENGTH || !in_array($tokenId, array_map(static fn (VaultExtensionTokenView $t): string => $t->id, $this->tokens($owner, $userId)), true)) {
            return false;
        }

        return $this->delete($userId, [$tokenId], VaultExtensionTokenRevokedEvent::REASON_ONE) === 1;
    }

    public function revokeAll(VaultUserRef $user): int
    {
        [$owner, $userId] = $this->user($user);
        $ids              = array_map(static fn (VaultExtensionTokenView $t): string => $t->id, $this->tokens($owner, $userId));

        return $ids === [] ? 0 : $this->delete($userId, $ids, VaultExtensionTokenRevokedEvent::REASON_ALL);
    }

    /**
     * A trusted user (a reference by id is looked up again, NJ-1) and its vault user id.
     *
     * @throws VaultAccessDeniedException when the user is unknown or has no id
     *
     * @return array{0: UserInterface, 1: string}
     */
    private function user(VaultUserRef $ref): array
    {
        $user = $this->refs->resolve($ref);
        $id   = $user instanceof UserInterface ? UserIdResolver::getId($user) : null;
        if (!$user instanceof UserInterface || $id === null || $id === '') {
            throw new VaultAccessDeniedException();
        }

        return [$user, $id];
    }

    /**
     * @return list<VaultExtensionTokenView>
     */
    private function tokens(UserInterface $owner, string $userId): array
    {
        $em = $this->em();
        if ($em->getMetadataFactory()->isTransient(VaultExtensionToken::class)) {
            $views = array_map(static fn (array $row): VaultExtensionTokenView => new VaultExtensionTokenView($row['id'], $row['created_at'], $row['expires_at'], $row['last_used_at']), $this->table()->rowsForUser($em, $userId, $this->mapsById()));
        } else {
            try {
                $parameter = $this->users instanceof VaultUserReferenceResolverInterface ? $this->users->reference($owner) : $owner;
            } catch (AccessDeniedException) {
                return []; // A user the vault does not accept has no token.
            }
            /** @var list<array{id: string, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable, lastUsedAt: DateTimeImmutable|null, owner: mixed}> $rows */
            $rows = $em->createQuery('SELECT t.id AS id, t.createdAt AS createdAt, t.expiresAt AS expiresAt, t.lastUsedAt AS lastUsedAt, ' . ($this->mapsById() ? 't.user' : 'IDENTITY(t.user)') . ' AS owner FROM ' . VaultExtensionToken::class . ' t WHERE t.user = :user')
                ->setParameter('user', $parameter)
                ->getArrayResult();
            $views = [];
            foreach ($rows as $row) {
                // REQ-SEC-006 / NC2-2: the stored owner equals the id byte for byte, whatever the collation or column type.
                if (is_scalar($row['owner']) && (string) $row['owner'] === $userId) {
                    $views[] = new VaultExtensionTokenView($row['id'], $row['createdAt'], $row['expiresAt'], $row['lastUsedAt']);
                }
            }
        }

        usort($views, static fn (VaultExtensionTokenView $a, VaultExtensionTokenView $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);

        return $views;
    }

    /**
     * Deletes the ids in one write unit and announces the rows it deleted.
     *
     * @param non-empty-list<string> $ids ids of the user's own tokens
     */
    private function delete(string $userId, array $ids, string $reason): int
    {
        $em        = $this->em();
        $transient = $em->getMetadataFactory()->isTransient(VaultExtensionToken::class);
        $table     = $this->table();
        $operation = function () use ($em, $ids, $transient, $table, $userId, $reason): int {
            $deleted = [];
            // One conditional delete per id: only the rows this call deleted are announced.
            foreach ($ids as $id) {
                $count = $transient
                    ? $table->deleteById($em, $id)
                    : (int) $em->createQuery('DELETE FROM ' . VaultExtensionToken::class . ' t WHERE t.id = :id')->setParameter('id', $id)->execute();
                if ($count === 1) {
                    $deleted[] = $id;
                }
            }
            if ($deleted !== []) {
                $this->eventDispatcher?->dispatch(new VaultExtensionTokenRevokedEvent($userId, $deleted, $reason, $this->clock?->now() ?? new DateTimeImmutable()), VaultEvents::EXTENSION_TOKEN_REVOKED);
            }

            return count($deleted);
        };

        return $this->writeUnit instanceof VaultWriteUnit ? $this->writeUnit->run($operation) : $em->wrapInTransaction(static fn (): int => $operation());
    }

    private function mapsById(): bool
    {
        return $this->users?->mapsById() === true;
    }

    private function table(): VaultExtensionTokenTable
    {
        return $this->tokenTable ?? new VaultExtensionTokenTable();
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The extension token commands need a Doctrine ORM entity manager.');
        }

        return $em;
    }
}
