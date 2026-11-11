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

use function array_column;
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

        $received = $this->deleteGrants($em, 'g.granteeType = :granteeType AND g.granteeId = :granteeId', ['granteeType' => GranteeType::User, 'granteeId' => $userId], $batchSize);
        $personal = $this->deleteGrants(
            $em,
            '(g.resourceType = :item AND g.resourceId IN (SELECT pi.id FROM ' . VaultItem::class . ' pi WHERE pi.creator = :user AND pi.ownerScope IS NULL))'
            . ' OR (g.resourceType = :folder AND g.resourceId IN (SELECT pf.id FROM ' . VaultFolder::class . ' pf WHERE pf.creator = :user AND pf.ownerScope IS NULL))',
            ['item' => VaultResourceType::Item, 'folder' => VaultResourceType::Folder, 'user' => $who],
            $batchSize,
            'user:' . $userId,
        );
        $grantsCreated = $this->execute($em, 'UPDATE ' . VaultGrant::class . ' g SET g.createdBy = NULL WHERE g.createdBy = :user', ['user' => $who]);

        [$itemTags, $items] = $this->deleteItems($em, $who, $batchSize);
        $folders            = $this->deleteFolders($em, $who, $batchSize);
        [$tagLinks, $tags]  = $this->deleteTags($em, $who, $batchSize);

        $scopeItems   = $this->execute($em, 'UPDATE ' . VaultItem::class . ' i SET i.createdBy = NULL WHERE i.createdBy = :user', ['user' => $who]);
        $scopeFolders = $this->execute($em, 'UPDATE ' . VaultFolder::class . ' f SET f.createdBy = NULL WHERE f.createdBy = :user', ['user' => $who]);
        $tokens       = $this->execute($em, 'DELETE FROM ' . VaultExtensionToken::class . ' t WHERE t.user = :user', ['user' => $who]);

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
     * @return array{0: int, 1: int} tag links, items
     */
    private function deleteItems(EntityManagerInterface $em, object|string $who, int $batchSize): array
    {
        [$joinTable, $itemColumn] = $this->tagJoinTable($em);
        $links                    = 0;
        $items                    = 0;
        while (true) {
            /** @var list<string> $ids */
            $ids = array_column($em->createQuery('SELECT i.id AS id FROM ' . VaultItem::class . ' i WHERE i.creator = :user AND i.ownerScope IS NULL ORDER BY i.id ASC')
                ->setParameter('user', $who)->setMaxResults($batchSize)->getArrayResult(), 'id');
            if ($ids === []) {
                return [$links, $items];
            }

            [$l, $i] = $em->wrapInTransaction(static fn (EntityManagerInterface $em): array => [
                (int) $em->getConnection()->executeStatement('DELETE FROM ' . $joinTable . ' WHERE ' . $itemColumn . ' IN (?)', [$ids], [ArrayParameterType::STRING]),
                (int) $em->createQuery('DELETE FROM ' . VaultItem::class . ' i WHERE i.id IN (:ids)')->setParameter('ids', $ids)->execute(),
            ]);
            $links += $l;
            $items += $i;
        }
    }

    /**
     * Personal folders, deepest first (a parent is deleted after its children), per batch.
     */
    private function deleteFolders(EntityManagerInterface $em, object|string $who, int $batchSize): int
    {
        /** @var list<array{id: string, parentId: string|null}> $rows */
        $rows = $em->createQuery('SELECT f.id AS id, IDENTITY(f.parent) AS parentId FROM ' . VaultFolder::class . ' f WHERE f.creator = :user AND f.ownerScope IS NULL')
            ->setParameter('user', $who)->getArrayResult();
        $parents = [];
        foreach ($rows as $row) {
            $parents[$row['id']] = is_string($row['parentId']) ? $row['parentId'] : null;
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
     * @return array{0: int, 1: int} tag links, tags
     */
    private function deleteTags(EntityManagerInterface $em, object|string $who, int $batchSize): array
    {
        [$joinTable, , $tagColumn] = $this->tagJoinTable($em);
        $links                     = 0;
        $tags                      = 0;
        while (true) {
            /** @var list<string> $ids */
            $ids = array_column($em->createQuery('SELECT t.id AS id FROM ' . VaultTag::class . ' t WHERE t.creator = :user AND t.ownerScope IS NULL ORDER BY t.id ASC')
                ->setParameter('user', $who)->setMaxResults($batchSize)->getArrayResult(), 'id');
            if ($ids === []) {
                return [$links, $tags];
            }

            [$l, $t] = $em->wrapInTransaction(static fn (EntityManagerInterface $em): array => [
                (int) $em->getConnection()->executeStatement('DELETE FROM ' . $joinTable . ' WHERE ' . $tagColumn . ' IN (?)', [$ids], [ArrayParameterType::STRING]),
                (int) $em->createQuery('DELETE FROM ' . VaultTag::class . ' t WHERE t.id IN (:ids)')->setParameter('ids', $ids)->execute(),
            ]);
            $links += $l;
            $tags += $t;
        }
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
     * @param array<string, mixed> $parameters
     */
    private function execute(EntityManagerInterface $em, string $dql, array $parameters): int
    {
        $query = $em->createQuery($dql);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return (int) $em->wrapInTransaction(static fn (): int => (int) $query->execute());
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
