<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Bridge\SearchKit;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Orx;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use Nowo\VaultBundle\Config\VaultRuntimeConfigProvider;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultSearchScopesEvent;
use Nowo\VaultBundle\Repository\ResolvesEntityManagerTrait;
use Nowo\VaultBundle\Security\VaultAccessCheckerInterface;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Security\VaultTeamMembershipResolverInterface;
use Nowo\VaultBundle\Service\VaultAccessGuard;
use Nowo\VaultBundle\Support\VaultUserRefs;
use Nowo\VaultBundle\VaultBundle;
use Psr\Clock\ClockInterface;
use SortDirection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function is_array;
use function is_string;
use function max;
use function min;
use function preg_match;
use function sprintf;

/**
 * The SearchKit group `vault_items` (1.21.0, SearchKit >= 1.9): the vault items the viewer may open, matched on their
 * NAME only.
 *
 * - Only the `title` column is searched (escaped LIKE, by SearchKit). Secret values, notes, URLs, attachments, custom
 *   fields, tags and history live in the encrypted payload or other tables and are never matched, shown or decrypted:
 *   the definition has no cryptographer and never reads the ciphertext.
 * - A row shows the name, the item type and the folder name when the viewer may see that folder (see folderName()).
 * - SQL first: active items (not in the trash) that are the viewer's own personal items, shared with the viewer or one
 *   of its teams by an active item or folder grant, or in a scope vault (narrowed by VaultEvents::SEARCH_SCOPES).
 * - Then Vault's own check on the whole candidate window, in one batch: VaultAccessGuard::resolvePermissionMap()
 *   (ownership by exact id, scope membership through the scope access resolver, active grants compared byte for byte,
 *   the ITEM_ACCESS_CHECK listeners), plus an exact re-check of scope membership and of the viewer. Then SearchKit's
 *   shared visibility event. Any refusal: null, no count, no hint.
 * - The group is sensitive(): SearchKit runs it only while its recents are revalidated or off.
 * - The viewer is the signed-in vault user whose identifier is the query's (VaultSearchViewers); its vault user id is
 *   the SearchKit "tenant", so the rows of another user are another tenant's.
 * - Feature gate: the vault's own access checker (`canAccess()` and `canList()`, as the items page).
 *
 * Stateless (FrankenPHP worker mode, kernel reset off): the viewer, its teams, the routes and the clock are read on
 * every call; the entity manager is re-resolved when a previous flush closed it.
 */
final class VaultItemSearchDefinition extends EntitySearchDefinition
{
    use ResolvesEntityManagerTrait;

    /** Group key. */
    public const GROUP = 'vault_items';

    /** Subject type of SearchKit's host visibility event. */
    public const SUBJECT_TYPE = 'vault_item';

    /** The shape of an item id (a lower-case UUID, as the bundle generates them). */
    private const ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    /** Route names used when the runtime configuration has none. */
    private const DEFAULT_ROUTES = ['item_view' => 'nowo_vault_item_view', 'items' => 'nowo_vault_items'];

    /**
     * @param bool $scopesEnabled `nowo_vault.scopes.enabled`
     * @param int $maxCandidates `nowo_vault.search_kit.max_candidates`
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VaultSearchViewers $viewers,
        private readonly VaultAccessGuard $accessGuard,
        private readonly VaultAccessCheckerInterface $accessChecker,
        private readonly VaultTeamMembershipResolverInterface $teams,
        private readonly UrlGeneratorInterface $urls,
        private readonly ?ManagerRegistry $registry = null,
        private readonly ?string $managerName = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?VaultRuntimeConfigProvider $runtimeConfig = null,
        private readonly ?ClockInterface $clock = null,
        private readonly bool $scopesEnabled = false,
        private readonly int $maxCandidates = self::DEFAULT_MAX_CANDIDATES,
    ) {
    }

    public function group(): string
    {
        return self::GROUP;
    }

    public function label(SearchQuery $query): string
    {
        return $this->translated('vault.search_kit.group', 'Vault items', $query->locale);
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function tenant(SearchQuery $query): ?string
    {
        return $this->viewers->forIdentifier($query->userIdentifier)?->id;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);

        return $viewer instanceof VaultSearchViewer && $this->accessChecker->canAccess($viewer->user) && $this->accessChecker->canList($viewer->user);
    }

    /**
     * The item name only.
     */
    public function searchableFields(): array
    {
        return ['i.title'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        $queryBuilder = $this->em()->createQueryBuilder()
            ->select('i', 'f')
            ->from(VaultItem::class, 'i')
            ->leftJoin('i.folder', 'f')
            ->where('i.deletedAt IS NULL')
            ->orderBy('i.updatedAt', SortDirection::Descending)
            ->addOrderBy('i.id', SortDirection::Ascending);

        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);
        if (!$viewer instanceof VaultSearchViewer) {
            return $queryBuilder->andWhere('1 = 0');
        }

        $teamIds  = array_map(strval(...), $this->teams->getTeamIdsForUser($viewer->user));
        $grantees = 'g%1$s.granteeType = :vault_search_user_type AND g%1$s.granteeId = :vault_search_user_id';
        if ($teamIds !== []) {
            $grantees = '(' . $grantees . ') OR (g%1$s.granteeType = :vault_search_team_type AND g%1$s.granteeId IN (:vault_search_team_ids))';
            $queryBuilder->setParameter('vault_search_team_type', GranteeType::Team)->setParameter('vault_search_team_ids', $teamIds);
        }
        $granted = 'SELECT g%1$s.resourceId FROM ' . VaultGrant::class . ' g%1$s WHERE g%1$s.resourceType = :vault_search_%2$s AND (' . $grantees . ') AND (g%1$s.expiresAt IS NULL OR g%1$s.expiresAt > :vault_search_now)';

        $visible = $queryBuilder->expr()->orX(
            'i.ownerScope IS NULL AND i.creator = :vault_search_viewer',
            'i.id IN (' . sprintf($granted, 'i', 'item') . ')',
            'f.id IN (' . sprintf($granted, 'f', 'folder') . ')',
        );
        $queryBuilder
            ->setParameter('vault_search_viewer', $viewer->reference)
            ->setParameter('vault_search_user_type', GranteeType::User)
            ->setParameter('vault_search_user_id', $viewer->id)
            ->setParameter('vault_search_item', VaultResourceType::Item)
            ->setParameter('vault_search_folder', VaultResourceType::Folder)
            ->setParameter('vault_search_now', $this->clock?->now() ?? new DateTimeImmutable());

        $this->applyScopeVaults($queryBuilder, $visible, $viewer);

        return $queryBuilder->andWhere($visible);
    }

    /**
     * Vault's own check, once for the whole window (one grant query), then exact re-checks.
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        $viewer = $this->viewers->forTenant($tenant, $query->userIdentifier);
        if (!$viewer instanceof VaultSearchViewer) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if ($row instanceof VaultItem) {
                $items[] = $row;
            }
        }
        $permissions = $this->accessGuard->resolvePermissionMap($viewer->user, $items);

        $visible = [];
        foreach ($items as $item) {
            if (($permissions[$item->getId()] ?? null) instanceof VaultPermission && $this->isWithinReach($item, $viewer)) {
                $visible[] = $item;
            }
        }

        return $visible;
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        if (!$row instanceof VaultItem) {
            throw new LogicException('A vault_items search row must be a VaultItem.');
        }
        $type     = $row->getItemType();
        $subtitle = $this->translated('vault.type.' . $type->value, $type->value, $query->locale);
        $folder   = $this->folderName($row, $query);
        if ($folder !== null) {
            $subtitle .= ' · ' . $folder;
        }

        return new SearchItem(
            $row->getTitle(),
            $this->urls->generate($this->route('item_view'), ['id' => $row->getId()]),
            $subtitle,
        );
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return SeeAllLink::build($this->urls, $this->route('items'), [], $query);
    }

    public function maxCandidates(): int
    {
        return max(1, min($this->maxCandidates, 1000));
    }

    /**
     * Item names can be private ("Divorce lawyer", "Clinic portal"): the group never runs while SearchKit keeps recents
     * in the browser without revalidating them.
     */
    public function sensitive(): bool
    {
        return true;
    }

    public function idField(): string
    {
        return 'i.id';
    }

    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters['id'] ?? null;

        // Client input: the item view route and a lower-case UUID only.
        return $reference->route === $this->route('item_view') && is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    /**
     * Adds the scope vault items to the SQL condition, as narrowed by the SEARCH_SCOPES listeners.
     */
    private function applyScopeVaults(OrmQueryBuilder $queryBuilder, Orx $visible, VaultSearchViewer $viewer): void
    {
        if (!$this->scopesEnabled) {
            return;
        }

        $event = new VaultSearchScopesEvent(VaultUserRefs::fromUser($viewer->user));
        $this->eventDispatcher?->dispatch($event, VaultEvents::SEARCH_SCOPES);
        $only     = $event->getScopes();
        $excluded = $event->getExcluded();

        if ($only === null) {
            $visible->add('i.ownerScope IS NOT NULL');
        } elseif ($only !== []) {
            $visible->add('i.ownerScope IN (:vault_search_scopes)');
            $queryBuilder->setParameter('vault_search_scopes', $only);
        }
        // Narrowing applies to shared scope items too: an excluded scope is never searched.
        if ($only !== null) {
            $queryBuilder->andWhere($only === [] ? 'i.ownerScope IS NULL' : 'i.ownerScope IS NULL OR i.ownerScope IN (:vault_search_scopes)');
        }
        if ($excluded !== []) {
            $queryBuilder->andWhere('i.ownerScope IS NULL OR i.ownerScope NOT IN (:vault_search_excluded)')->setParameter('vault_search_excluded', $excluded);
        }
    }

    /**
     * Exact re-checks after the guard, whatever the SQL matched (a host column with a case- or accent-folding
     * collation matches `UserA` for `usera`):
     * - a scope item only for a member of its scope (the scope access resolver, memoized per request);
     * - a personal item has an owner id; the guard treated the viewer as its owner only when that id is byte for byte
     *   the viewer's (`UserIdResolver::isSameUser()`, `===`), otherwise it needed an exact grant (grantee ids compared
     *   with `===`) or a host ITEM_ACCESS_CHECK listener. A look-alike owner (`UserA` for `usera`, `5 ` for `5`) is
     *   therefore never the viewer, and the folder name is shown only for an exact owner (folderName()).
     */
    private function isWithinReach(VaultItem $item, VaultSearchViewer $viewer): bool
    {
        $scope = $item->getOwnerScope();
        if ($scope !== null) {
            return $this->accessGuard->resolveScopeAccess($viewer->user, $scope) instanceof VaultScopeAccess;
        }

        return $item->getCreatorId() !== null;
    }

    /**
     * The folder name when the viewer may see the folder without a further query: the folder has the item's owner and
     * is not trashed, and it is the viewer's own personal folder (exact id) or a folder of a scope where the viewer has
     * a baseline (sees every folder of the scope); FOLDER_ACCESS_CHECK listeners may still refuse it. A viewer who only
     * holds a grant sees no folder name.
     */
    private function folderName(VaultItem $item, SearchQuery $query): ?string
    {
        $folder = $item->getFolder();
        if (!$folder instanceof VaultFolder || $folder->isDeleted() || !$folder->getOwner()->equals($item->getOwner())) {
            return null;
        }
        $viewer = $this->viewers->forIdentifier($query->userIdentifier);
        if (!$viewer instanceof VaultSearchViewer) {
            return null;
        }

        $scope   = $folder->getOwnerScope();
        $granted = $scope === null
            ? $folder->getCreatorId() === $viewer->id
            : $this->accessGuard->resolveScopeAccess($viewer->user, $scope)?->baseline instanceof VaultPermission;
        if (!$granted) {
            return null;
        }

        $event = new VaultFolderAccessCheckEvent($viewer->user, $folder, VaultAccessAction::View, true);
        $this->eventDispatcher?->dispatch($event, VaultEvents::FOLDER_ACCESS_CHECK);

        return $event->isGranted() ? $folder->getName() : null;
    }

    /**
     * The configured name of a vault route.
     */
    private function route(string $key): string
    {
        $routes = $this->runtimeConfig?->get()['routes'] ?? null;
        $name   = is_array($routes) && is_array($routes[$key] ?? null) ? ($routes[$key]['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_ROUTES[$key];
    }

    private function translated(string $key, string $fallback, ?string $locale): string
    {
        $translated = $this->translator?->trans($key, [], VaultBundle::TRANSLATION_DOMAIN, $locale);

        return is_string($translated) && $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
