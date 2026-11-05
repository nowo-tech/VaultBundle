<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VaultBundle\Dto\VaultCatalogueEntry;
use Nowo\VaultBundle\Dto\VaultCataloguePage;
use Nowo\VaultBundle\Dto\VaultCatalogueQuery;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultCatalogueAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Exception\VaultAccessDeniedException;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use SortDirection;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_column;
use function is_string;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function str_replace;
use function trim;

/**
 * Metadata-only catalogue of a scope vault (1.16.0, gap 3): which items exist, so a member can request access. Built
 * from scalar DQL selects, so no `VaultItem` (and no ciphertext) is ever hydrated: id, title, type, scope tag names,
 * owner scope and the date (not time) of the last change of every active item that is not `catalogue_hidden`.
 * Constant number of queries per page (entries, count, tags, grants).
 */
final readonly class VaultCatalogue
{
    private const MIN_SEARCH = 2;
    private const MAX_SEARCH = 100;

    /**
     * Stores the Doctrine registry, the access guard and the dispatcher.
     */
    public function __construct(
        private ManagerRegistry $registry,
        private VaultAccessGuard $accessGuard,
        private EventDispatcherInterface $eventDispatcher,
        private ?string $managerName = null,
    ) {
    }

    /**
     * One page of the catalogue of `$scope` for `$viewer`.
     *
     * @throws VaultAccessDeniedException when the viewer is not a member, the resolver gives no `catalogue`, or
     *                                    CATALOGUE_ACCESS_CHECK denies
     */
    public function page(UserInterface $viewer, string $scope, VaultCatalogueQuery $query): VaultCataloguePage
    {
        $access = $this->accessGuard->resolveScopeAccess($viewer, $scope);
        if (!$access instanceof VaultScopeAccess) {
            throw new VaultAccessDeniedException();
        }

        $check = new VaultCatalogueAccessCheckEvent($viewer, $scope, $access->catalogue);
        $this->eventDispatcher->dispatch($check, VaultEvents::CATALOGUE_ACCESS_CHECK);
        if (!$check->isGranted()) {
            throw new VaultAccessDeniedException();
        }

        $perPage = max(1, $query->perPage);
        $page    = max(1, $query->page);
        $em      = $this->em();

        $total = (int) $this->filtered($em, $scope, $query)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();

        /** @var list<array{id: string, title: string, itemType: string|VaultItemType, updatedAt: DateTimeInterface, folderId: string|null, folderScope: string|null}> $rows */
        $rows = $this->filtered($em, $scope, $query)
            ->select('i.id AS id', 'i.title AS title', 'i.itemType AS itemType', 'i.updatedAt AS updatedAt', 'f.id AS folderId', 'f.ownerScope AS folderScope')
            ->leftJoin('i.folder', 'f')
            ->orderBy('i.updatedAt', SortDirection::Descending)
            ->addOrderBy('i.id', SortDirection::Ascending)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getArrayResult();

        if ($rows === []) {
            return new VaultCataloguePage([], $total, $page, $perPage);
        }

        $ids         = array_column($rows, 'id');
        $tags        = $this->tags($em, $scope, $ids);
        $itemFolders = [];
        foreach ($rows as $row) {
            $itemFolders[$row['id']] = $row['folderScope'] === $scope ? $row['folderId'] : null;
        }
        $access  = $this->accessGuard->resolveScopeViewMap($viewer, $scope, $itemFolders);
        $entries = [];
        foreach ($rows as $row) {
            $type      = $row['itemType'] instanceof VaultItemType ? $row['itemType'] : VaultItemType::from($row['itemType']);
            $entries[] = new VaultCatalogueEntry(
                $row['id'],
                $row['title'],
                $type,
                $tags[$row['id']] ?? [],
                $scope,
                new DateTimeImmutable($row['updatedAt']->format('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC')),
                $access[$row['id']] ?? false,
            );
        }

        return new VaultCataloguePage($entries, $total, $page, $perPage);
    }

    /**
     * Base query of the visible rows (active, not hidden, filters applied); select added by the caller.
     */
    private function filtered(EntityManagerInterface $em, string $scope, VaultCatalogueQuery $query): QueryBuilder
    {
        $qb = $em->createQueryBuilder()
            ->from(VaultItem::class, 'i')
            ->where('i.ownerScope = :scope')
            ->andWhere('i.deletedAt IS NULL')
            ->andWhere('i.catalogueHidden = false')
            ->setParameter('scope', $scope);

        $search = trim((string) $query->search);
        if (mb_strlen($search) >= self::MIN_SEARCH) {
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($search, 0, self::MAX_SEARCH)));
            $qb->andWhere("LOWER(i.title) LIKE :search ESCAPE '!'")->setParameter('search', '%' . $needle . '%');
        }
        if ($query->type instanceof VaultItemType) {
            $qb->andWhere('i.itemType = :type')->setParameter('type', $query->type);
        }
        if (is_string($query->tagId) && $query->tagId !== '') {
            $qb->innerJoin('i.tags', 'ft', 'WITH', 'ft.id = :tagId AND ft.ownerScope = :scope')->setParameter('tagId', $query->tagId);
        }

        return $qb;
    }

    /**
     * Scope tag names per item id (one scalar query).
     *
     * @param list<string> $ids
     *
     * @return array<string, list<string>>
     */
    private function tags(EntityManagerInterface $em, string $scope, array $ids): array
    {
        /** @var list<array{itemId: string, name: string}> $rows */
        $rows = $em->createQueryBuilder()
            ->select('i.id AS itemId', 't.name AS name')
            ->from(VaultItem::class, 'i')
            ->innerJoin('i.tags', 't')
            ->where('i.id IN (:ids)')
            ->andWhere('t.ownerScope = :scope')
            ->setParameter('ids', $ids)
            ->setParameter('scope', $scope)
            ->orderBy('t.name', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        $tags = [];
        foreach ($rows as $row) {
            $tags[$row['itemId']][] = $row['name'];
        }

        return $tags;
    }

    private function em(): EntityManagerInterface
    {
        $em = $this->registry->getManager($this->managerName);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The vault catalogue needs a Doctrine ORM entity manager.');
        }

        return $em;
    }
}
