<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Dto\VaultErasureResult;
use Nowo\VaultBundle\Entity\VaultExtensionToken;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultGrantChangeEvent;
use Nowo\VaultBundle\Event\VaultPersonalDataErasedEvent;
use Nowo\VaultBundle\Repository\VaultGrantExpiryRepositoryInterface;
use Nowo\VaultBundle\Repository\VaultGrantRepositoryInterface;
use Nowo\VaultBundle\Support\VaultUserLocatorInterface;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_chunk;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function arsort;
use function count;
use function is_string;
use function max;

/**
 * Erases the vault data of one user (1.16.0, contract §8.2, GDPR erasure), in this order and without relying on
 * `ON DELETE CASCADE`, one transaction per batch:
 *
 * 1. grants to the user → deleted (`GRANT_REVOKED`, reason `erasure`);
 * 2. grants on the user's personal items and folders → deleted (`GRANT_REVOKED`, reason `erasure`);
 * 3. the remaining grants the user created (scope resources) → kept, `created_by_id` set to NULL;
 * 4. tag links of personal items, then the personal items (trash included);
 * 5. personal folders (children first), then personal tags;
 * 6. scope items and folders the user created → `created_by_id` set to NULL;
 * 7. the user's browser extension tokens.
 *
 * Then `PERSONAL_DATA_ERASED` (user id and counts). Idempotent: a second run counts zero. Call it **before** deleting
 * the user row. It writes with DQL / DBAL statements only (no flush of the host's unit of work).
 */
final readonly class VaultPersonalDataEraser
{
    /**
     * Stores the Doctrine registry and the collaborators.
     */
    public function __construct(
        private ManagerRegistry $registry,
        private VaultGrantService $grantService,
        private VaultGrantRepositoryInterface $grantRepository,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?VaultUserLocatorInterface $userLocator = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        private ?string $managerName = null,
        private ?VaultUserReferenceResolverInterface $users = null,
    ) {
    }

    /**
     * Erases the user's vault data and returns the rows touched per step.
     */
    public function erase(string $userId, int $batchSize = 500): VaultErasureResult
    {
        $batchSize = max(1, $batchSize);
        $em        = $this->em();
        // 1.17.0: with the id mapping the user columns hold the id itself.
        $who = $this->users?->mapsById() === true ? $userId : ($this->userLocator?->find($userId) ?? $userId);

        // 1.17.0 (LQ-V-M1): every step works on the ids of the user's rows. With the id mapping those ids are kept only
        // when the stored user id equals $userId byte for byte, whatever the column collation.
        $personalItems   = $this->ownedIds($em, VaultItem::class, 'creator', $who, $userId, ' AND r.ownerScope IS NULL');
        $personalFolders = $this->ownedIds($em, VaultFolder::class, 'creator', $who, $userId, ' AND r.ownerScope IS NULL');

        $received = $this->deleteGrants($em, 'g.granteeType = :granteeType AND g.granteeId = :granteeId', ['granteeType' => GranteeType::User, 'granteeId' => $userId], $batchSize);
        $personal = $this->deleteGrants(
            $em,
            '(g.resourceType = :item AND g.resourceId IN (:itemIds)) OR (g.resourceType = :folder AND g.resourceId IN (:folderIds))',
            // An empty IN list is invalid SQL: '' is never a resource id.
            ['item' => VaultResourceType::Item, 'folder' => VaultResourceType::Folder, 'itemIds' => $personalItems !== [] ? $personalItems : [''], 'folderIds' => $personalFolders !== [] ? $personalFolders : ['']],
            $batchSize,
            'user:' . $userId,
        );
        $grantsCreated = $this->updateByIds($em, VaultGrant::class, 'createdBy', $this->ownedIds($em, VaultGrant::class, 'createdBy', $who, $userId), $batchSize);

        [$itemTags, $items] = $this->deleteItems($em, $personalItems, $batchSize);
        $folders            = $this->deleteFolders($em, $personalFolders, $batchSize);
        [$tagLinks, $tags]  = $this->deleteTags($em, $this->ownedIds($em, VaultTag::class, 'creator', $who, $userId, ' AND r.ownerScope IS NULL'), $batchSize);

        $scopeItems   = $this->updateByIds($em, VaultItem::class, 'createdBy', $this->ownedIds($em, VaultItem::class, 'createdBy', $who, $userId), $batchSize);
        $scopeFolders = $this->updateByIds($em, VaultFolder::class, 'createdBy', $this->ownedIds($em, VaultFolder::class, 'createdBy', $who, $userId), $batchSize);
        $tokens       = $this->deleteByIds($em, VaultExtensionToken::class, $this->ownedIds($em, VaultExtensionToken::class, 'user', $who, $userId), $batchSize);

        $result = new VaultErasureResult($received, $personal, $grantsCreated, $itemTags + $tagLinks, $items, $folders, $tags, $scopeItems, $scopeFolders, $tokens);
        $this->eventDispatcher?->dispatch(new VaultPersonalDataErasedEvent($userId, $result->toArray(), $this->now()), VaultEvents::PERSONAL_DATA_ERASED);
        ($this->logger ?? new NullLogger())->info('Vault personal data erased.', ['bundle' => 'nowo_vault', 'action' => 'personal_data_erased', 'user_id' => $userId] + $result->toArray());

        return $result;
    }

    /**
     * Deletes the matching grants in batches (one transaction each), then emits GRANT_REVOKED (reason `erasure`) for
     * every row this call deleted.
     *
     * @param array<string, mixed> $parameters
     */
    private function deleteGrants(EntityManagerInterface $em, string $where, array $parameters, int $batchSize, ?string $ownerKey = null): int
    {
        $total = 0;
        while (true) {
            $query = $em->createQuery('SELECT g FROM ' . VaultGrant::class . ' g WHERE ' . $where . ' ORDER BY g.id ASC')->setMaxResults($batchSize);
            foreach ($parameters as $name => $value) {
                $query->setParameter($name, $value);
            }
            /** @var list<VaultGrant> $grants */
            $grants = $query->getResult();
            if ($grants === []) {
                return $total;
            }

            $keys = $ownerKey === null && $this->grantRepository instanceof VaultGrantExpiryRepositoryInterface
                ? $this->grantRepository->resolveOwnerKeys(array_map(static fn (VaultGrant $g): array => [$g->getResourceType(), $g->getResourceId()], $grants))
                : [];
            // Conditional delete per row: an event only for the rows this call deleted (a concurrent revoke or purge wins).
            $deleted = $em->wrapInTransaction(static function (EntityManagerInterface $em) use ($grants): array {
                $gone = [];
                foreach ($grants as $grant) {
                    if ((int) $em->createQuery('DELETE FROM ' . VaultGrant::class . ' g WHERE g.id = :id')->setParameter('id', $grant->getId())->execute() === 1) {
                        $gone[] = $grant;
                    }
                    $em->detach($grant);
                }

                return $gone;
            });
            foreach ($deleted as $grant) {
                $this->grantService->dispatchChange(
                    VaultEvents::GRANT_REVOKED,
                    $grant,
                    null,
                    VaultGrantChangeEvent::REASON_ERASURE,
                    ownerKey: $ownerKey ?? $keys[$grant->getResourceType()->value . ':' . $grant->getResourceId()] ?? null,
                );
            }
            $total += count($deleted);
        }
    }

    /**
     * Tag links, then the personal items (trash included), per batch.
     *
     * @param list<string> $itemIds the user's personal items
     * @param int<1, max> $batchSize
     *
     * @return array{0: int, 1: int}
     */
    private function deleteItems(EntityManagerInterface $em, array $itemIds, int $batchSize): array
    {
        [$joinTable, $itemColumn] = $this->tagJoinTable($em);
        $links                    = 0;
        $items                    = 0;
        foreach (array_chunk($itemIds, $batchSize) as $ids) {
            [$l, $i] = $em->wrapInTransaction(static fn (EntityManagerInterface $em): array => [
                (int) $em->getConnection()->executeStatement('DELETE FROM ' . $joinTable . ' WHERE ' . $itemColumn . ' IN (?)', [$ids], [ArrayParameterType::STRING]),
                (int) $em->createQuery('DELETE FROM ' . VaultItem::class . ' i WHERE i.id IN (:ids)')->setParameter('ids', $ids)->execute(),
            ]);
            $links += $l;
            $items += $i;
        }

        return [$links, $items];
    }

    /**
     * Personal folders, deepest first (a parent is deleted after its children), per batch.
     *
     * @param list<string> $folderIds the user's personal folders
     * @param int<1, max> $batchSize
     */
    private function deleteFolders(EntityManagerInterface $em, array $folderIds, int $batchSize): int
    {
        $parents = [];
        foreach (array_chunk($folderIds, $batchSize) as $chunk) {
            /** @var list<array{id: string, parentId: string|null}> $rows */
            $rows = $em->createQuery('SELECT f.id AS id, IDENTITY(f.parent) AS parentId FROM ' . VaultFolder::class . ' f WHERE f.id IN (:ids)')
                ->setParameter('ids', $chunk)->getArrayResult();
            foreach ($rows as $row) {
                $parents[$row['id']] = is_string($row['parentId']) ? $row['parentId'] : null;
            }
        }
        $depth = [];
        foreach (array_keys($parents) as $id) {
            $d    = 0;
            $seen = [$id => true];
            for ($p = $parents[$id]; $p !== null && array_key_exists($p, $parents) && !isset($seen[$p]); $p = $parents[$p]) {
                $seen[$p] = true;
                ++$d;
            }
            $depth[$id] = $d;
        }
        arsort($depth);
        $ordered = array_map(strval(...), array_keys($depth));

        $deleted = 0;
        for ($offset = 0; $offset < count($ordered); $offset += $batchSize) {
            $ids = array_slice($ordered, $offset, $batchSize);
            $deleted += (int) $em->wrapInTransaction(static fn (EntityManagerInterface $em): int => (int) $em->createQuery('DELETE FROM ' . VaultFolder::class . ' f WHERE f.id IN (:ids)')->setParameter('ids', $ids)->execute());
        }

        return $deleted;
    }

    /**
     * Personal tags (and any link left to them), per batch.
     *
     * @param list<string> $tagIds the user's personal tags
     * @param int<1, max> $batchSize
     *
     * @return array{0: int, 1: int}
     */
    private function deleteTags(EntityManagerInterface $em, array $tagIds, int $batchSize): array
    {
        [$joinTable, , $tagColumn] = $this->tagJoinTable($em);
        $links                     = 0;
        $tags                      = 0;
        foreach (array_chunk($tagIds, $batchSize) as $ids) {
            [$l, $t] = $em->wrapInTransaction(static fn (EntityManagerInterface $em): array => [
                (int) $em->getConnection()->executeStatement('DELETE FROM ' . $joinTable . ' WHERE ' . $tagColumn . ' IN (?)', [$ids], [ArrayParameterType::STRING]),
                (int) $em->createQuery('DELETE FROM ' . VaultTag::class . ' t WHERE t.id IN (:ids)')->setParameter('ids', $ids)->execute(),
            ]);
            $links += $l;
            $tags += $t;
        }

        return [$links, $tags];
    }

    /**
     * Name and columns of the item–tag join table (it follows `table_prefix`).
     *
     * @return array{0: string, 1: string, 2: string} table, item column, tag column
     */
    private function tagJoinTable(EntityManagerInterface $em): array
    {
        /** @var ManyToManyOwningSideMapping $mapping VaultItem::$tags is the owning side (#[ORM\JoinTable]) */
        $mapping = $em->getClassMetadata(VaultItem::class)->getAssociationMapping('tags');
        $table   = $mapping->joinTable->name;
        $item    = $mapping->joinTable->joinColumns[0]->name;
        $tag     = $mapping->joinTable->inverseJoinColumns[0]->name;

        return [$table, $item, $tag];
    }

    /**
     * Ids of the rows whose user column `$field` holds the user (1.17.0). With the id mapping the stored id is compared
     * with `===` too, so a case- or accent-insensitive column collation never adds another user's rows (LQ-V-M1).
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function ownedIds(EntityManagerInterface $em, string $class, string $field, object|string $who, string $userId, string $extraWhere = ''): array
    {
        $strict = $this->users?->mapsById() === true;
        /** @var list<array{id: string, owner?: mixed}> $rows */
        $rows = $em->createQuery('SELECT r.id AS id' . ($strict ? ', r.' . $field . ' AS owner' : '') . ' FROM ' . $class . ' r WHERE r.' . $field . ' = :user' . $extraWhere . ' ORDER BY r.id ASC')
            ->setParameter('user', $who)
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            if (!$strict || ($row['owner'] ?? null) === $userId) {
                $ids[] = (string) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Sets the user column to NULL on the given rows, one transaction per batch.
     *
     * @param class-string $class
     * @param list<string> $ids
     * @param int<1, max> $batchSize
     */
    private function updateByIds(EntityManagerInterface $em, string $class, string $field, array $ids, int $batchSize): int
    {
        $total = 0;
        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $total += (int) $em->wrapInTransaction(static fn (EntityManagerInterface $em): int => (int) $em->createQuery('UPDATE ' . $class . ' r SET r.' . $field . ' = NULL WHERE r.id IN (:ids)')->setParameter('ids', $chunk)->execute());
        }

        return $total;
    }

    /**
     * Deletes the given rows, one transaction per batch.
     *
     * @param class-string $class
     * @param list<string> $ids
     * @param int<1, max> $batchSize
     */
    private function deleteByIds(EntityManagerInterface $em, string $class, array $ids, int $batchSize): int
    {
        $total = 0;
        foreach (array_chunk($ids, $batchSize) as $chunk) {
            $total += (int) $em->wrapInTransaction(static fn (EntityManagerInterface $em): int => (int) $em->createQuery('DELETE FROM ' . $class . ' r WHERE r.id IN (:ids)')->setParameter('ids', $chunk)->execute());
        }

        return $total;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The personal-data eraser needs a Doctrine ORM entity manager.');
        }

        return $em;
    }
}
