<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Integration\SearchKit;

use DateTimeImmutable;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchProvider;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\VaultBundle\Bridge\SearchKit\VaultItemSearchDefinition;
use Nowo\VaultBundle\Bridge\SearchKit\VaultSearchViewers;
use Nowo\VaultBundle\Doctrine\VaultUserMapping;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultFolderAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultItemAccessCheckEvent;
use Nowo\VaultBundle\Event\VaultSearchScopesEvent;
use Nowo\VaultBundle\Security\VaultScopeAccess;
use Nowo\VaultBundle\Support\DoctrineVaultUserReferenceResolver;
use Nowo\VaultBundle\Support\VaultUserClassPolicy;
use Nowo\VaultBundle\Support\VaultUserReferenceResolverInterface;
use Nowo\VaultBundle\Tests\App\Ddd\DddUser;
use Nowo\VaultBundle\Tests\App\Ddd\OtherDomainUser;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function array_map;
use function implode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * `user_mapping: id` (a strict DDD host: string user ids, no user table): the SearchKit contract, then the vault rules
 * of the group — name only, never a secret; own, shared (user, team, folder grant) and scope items through Vault's own
 * guard; exact ids; the SEARCH_SCOPES narrowing; the folder name rule; host vetoes; routes, recents and the budget.
 */
#[Group('searchkit')]
final class VaultItemSearchIdMappingTest extends VaultItemSearchTestCase
{
    private const ALICE = 'vtsk-alice';

    private const BOB = 'vtsk-bob';

    protected static function userMapping(): string
    {
        return VaultUserMapping::ID;
    }

    protected static function userClass(): array
    {
        return [DddUser::class, []];
    }

    protected function userOf(int|string $id, string $identifier): DddUser
    {
        return new DddUser((string) $id, $identifier);
    }

    protected function ownerValue(int|string $id): string
    {
        return (string) $id;
    }

    protected function tenants(): array
    {
        return [self::ALICE, self::BOB];
    }

    public function testOnlyTheNameIsSearchedNeverSecretsTagsOrFolders(): void
    {
        $marker = $this->marker();
        $folder = $this->persistFolder(new VaultFolder('folder' . $marker, self::ALICE));
        $item   = new VaultItem(VaultItemType::Login, 'Bank', self::ALICE, 'password=' . $marker . ';notes=' . $marker . ';url=https://u:' . $marker . '@bank.example', $folder);
        $tag    = new VaultTag('tag' . $marker, self::ALICE);
        self::em()->persist($tag);
        $item->setTags([$tag]);
        $this->persistItem($item);
        $this->actAs(self::ALICE, self::VIEWER_A);

        self::assertNull($this->provider()->search($this->query($marker)), 'Ciphertext, tags and folder names are never matched.');
        self::assertSame(['i.title'], $this->definition()->searchableFields());
    }

    public function testARowShowsNameTypeAndOwnFolderAndNothingElse(): void
    {
        $marker = $this->marker();
        $folder = $this->persistFolder(new VaultFolder('Work <b>', self::ALICE));
        $id     = $this->persistItem(new VaultItem(VaultItemType::CreditCard, 'Card ' . $marker, self::ALICE, 'SECRET-CIPHERTEXT', $folder));
        $this->actAs(self::ALICE, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(1, $group->items);
        $row = $group->items[0];
        self::assertSame('Card ' . $marker, $row->title);
        self::assertSame('credit_card · Work <b>', $row->subtitle);
        self::assertSame('/tools/vault/items/' . $id, $row->url);
        self::assertNull($row->badge);
        self::assertStringNotContainsString('SECRET', json_encode($row->toArray(VaultItemSearchDefinition::GROUP), JSON_THROW_ON_ERROR));
        self::assertNull($group->moreUrl, 'No see-all link: no vault page lists the same set.');
        self::assertSame('Vault items', $group->label);
    }

    public function testTranslatedLabelAndType(): void
    {
        $marker = $this->marker();
        $this->persistItem(new VaultItem(VaultItemType::SecureNote, 'Note ' . $marker, self::ALICE, 'ciphertext'));
        $this->actAs(self::ALICE, self::VIEWER_A);
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['vault.search_kit.group' => 'Elementos de la bóveda', 'vault.type.secure_note' => 'Nota segura'], 'es', 'NowoVaultBundle');
        $definition = $this->searchDefinition(translator: $translator);

        $query = new SearchQuery($marker, null, 10, null, false, self::VIEWER_A, [], 'es');
        self::assertSame('Elementos de la bóveda', $definition->label($query));
        self::assertSame('Nota segura', $definition->toItem(self::em()->getRepository(VaultItem::class)->findOneBy(['title' => 'Note ' . $marker]) ?? new stdClass(), $query)->subtitle);
    }

    public function testItemsSharedByAnExactGrantAppearWithoutTheirFolderName(): void
    {
        $marker = $this->marker();
        $folder = $this->persistFolder(new VaultFolder('Bob private', self::BOB));
        $direct = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' direct', self::BOB, 'ciphertext', $folder));
        $team   = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' team', self::BOB, 'ciphertext'));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' folder', self::BOB, 'ciphertext', $folder));
        $expired   = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' expired', self::BOB, 'ciphertext'));
        $lookAlike = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' lookalike', self::BOB, 'ciphertext'));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' unshared', self::BOB, 'ciphertext'));
        $this->grant(VaultResourceType::Item, $direct, GranteeType::User, self::ALICE);
        $this->grant(VaultResourceType::Item, $team, GranteeType::Team, 'team-1');
        $this->grant(VaultResourceType::Folder, $folder->getId(), GranteeType::User, self::ALICE);
        $this->grant(VaultResourceType::Item, $expired, GranteeType::User, self::ALICE, new DateTimeImmutable('-1 hour'));
        $this->grant(VaultResourceType::Item, $lookAlike, GranteeType::User, 'VTSK-ALICE');
        $this->teamIds[self::ALICE] = ['team-1'];
        $this->actAs(self::ALICE, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertEqualsCanonicalizing([$marker . ' direct', $marker . ' team', $marker . ' folder'], $this->titles($group));
        self::assertSame(3, $group->total);
        foreach ($group->items as $row) {
            self::assertSame('login', $row->subtitle, 'A grantee sees no folder name.');
        }
    }

    public function testScopeItemsFollowMembershipBaselineAndGrants(): void
    {
        $marker = $this->marker();
        $folder = $this->persistFolder(VaultFolder::forScope('Ops', 'proj-a', null));
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' a', 'proj-a', null, 'ciphertext', $folder));
        $grantsOnly = $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' b granted', 'proj-b', null, 'ciphertext'));
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' b other', 'proj-b', null, 'ciphertext'));
        $outsider = $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' c', 'proj-c', null, 'ciphertext'));
        $this->grant(VaultResourceType::Item, $grantsOnly, GranteeType::User, self::ALICE);
        $this->grant(VaultResourceType::Item, $outsider, GranteeType::User, self::ALICE);
        $this->members[self::ALICE] = ['proj-a' => new VaultScopeAccess(VaultPermission::Read), 'proj-b' => new VaultScopeAccess(null)];
        $this->searchScopes(['proj-a', 'proj-b', 'proj-c']);
        $this->actAs(self::ALICE, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertEqualsCanonicalizing([$marker . ' a', $marker . ' b granted'], $this->titles($group), 'Baseline: every item; grants only: the granted ones; a non-member: nothing, grants included.');
        $subtitles = array_map(static fn (SearchItem $row): string => $row->subtitle, $group->items);
        self::assertContains('login · Ops', $subtitles, 'A member with a baseline sees the scope folder.');

        // Scopes off: no scope item is searched, granted or not.
        $this->scopesEnabled = false;
        self::assertNull($this->provider()->search($this->query($marker)));
    }

    public function testSearchScopesListenersNarrowInSql(): void
    {
        $marker = $this->marker();
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' a', 'proj-a', null, 'ciphertext'));
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' b', 'proj-b', null, 'ciphertext'));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' own', self::ALICE, 'ciphertext'));
        $this->members[self::ALICE] = ['proj-a' => new VaultScopeAccess(VaultPermission::Read), 'proj-b' => new VaultScopeAccess(VaultPermission::Read)];
        $this->actAs(self::ALICE, self::VIEWER_A);

        self::assertSame([$marker . ' own'], $this->titles($this->provider()->search($this->query($marker))), 'Fail closed: no listener, no scope vault.');

        $seen = [];
        $this->vaultEvents->addListener(VaultEvents::SEARCH_SCOPES, static function (VaultSearchScopesEvent $event) use (&$seen): void {
            $seen[] = $event->getUserId();
            $event->limitTo(['proj-a', 'proj-b', 'Not A Scope']);
        });
        self::assertEqualsCanonicalizing([$marker . ' a', $marker . ' b', $marker . ' own'], $this->titles($this->provider()->search($this->query($marker))));
        self::assertSame([self::ALICE], $seen);

        $this->vaultEvents->addListener(VaultEvents::SEARCH_SCOPES, static fn (VaultSearchScopesEvent $event) => $event->exclude(['proj-b']));
        self::assertEqualsCanonicalizing([$marker . ' a', $marker . ' own'], $this->titles($this->provider()->search($this->query($marker))));

        $this->vaultEvents->addListener(VaultEvents::SEARCH_SCOPES, static fn (VaultSearchScopesEvent $event) => $event->excludeAll());
        self::assertSame([$marker . ' own'], $this->titles($this->provider()->search($this->query($marker))));
    }

    public function testExcludeAloneSearchesNoScope(): void
    {
        $marker = $this->marker();
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' a', 'proj-a', null, 'ciphertext'));
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' b', 'proj-b', null, 'ciphertext'));
        $this->members[self::ALICE] = ['proj-a' => new VaultScopeAccess(VaultPermission::Read), 'proj-b' => new VaultScopeAccess(VaultPermission::Read)];
        $this->actAs(self::ALICE, self::VIEWER_A);
        $this->vaultEvents->addListener(VaultEvents::SEARCH_SCOPES, static fn (VaultSearchScopesEvent $event) => $event->exclude(['proj-a']));

        self::assertNull($this->provider()->search($this->query($marker)), 'exclude() without limitTo() names no scope.');
    }

    public function testHostVetoesApply(): void
    {
        $marker = $this->marker();
        $folder = $this->persistFolder(new VaultFolder('Hidden folder', self::ALICE));
        $denied = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' denied', self::ALICE, 'ciphertext'));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' kept', self::ALICE, 'ciphertext', $folder));
        $this->vaultEvents->addListener(VaultEvents::ITEM_ACCESS_CHECK, static function (VaultItemAccessCheckEvent $event) use ($denied): void {
            if ($event->getItemId() === $denied) {
                $event->deny();
            }
        });
        $this->vaultEvents->addListener(VaultEvents::FOLDER_ACCESS_CHECK, static fn (VaultFolderAccessCheckEvent $event) => $event->deny());
        $this->actAs(self::ALICE, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame([$marker . ' kept'], $this->titles($group));
        self::assertSame(1, $group->total);
        self::assertSame('login', $group->items[0]->subtitle, 'FOLDER_ACCESS_CHECK hides the folder name.');
    }

    public function testFolderNameNeedsTheItemsOwnerAndAnActiveFolder(): void
    {
        $marker  = $this->marker();
        $foreign = $this->persistFolder(new VaultFolder('Bob folder', self::BOB));
        $trashed = $this->persistFolder((new VaultFolder('Trashed', self::ALICE))->markDeleted());
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' x', self::ALICE, 'ciphertext', $foreign));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' y', self::ALICE, 'ciphertext', $trashed));
        $this->actAs(self::ALICE, self::VIEWER_A);

        $group = $this->provider()->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertSame(['login', 'login'], array_map(static fn (SearchItem $row): string => $row->subtitle, $group->items));

        // A row rendered once the viewer is gone (signed out mid-request) shows no folder name either.
        $own = new VaultItem(VaultItemType::Login, 'own', self::ALICE, 'ciphertext', new VaultFolder('Mine', self::ALICE));
        self::assertSame('login · Mine', $this->searchDefinition()->toItem($own, $this->query($marker))->subtitle);
        $this->tokens->setToken(null);
        self::assertSame('login', $this->searchDefinition()->toItem($own, $this->query($marker))->subtitle);
    }

    public function testLookAlikeOwnersAreNeverTheViewer(): void
    {
        $this->actAs('usera', self::VIEWER_A);
        $definition = $this->searchDefinition();
        $query      = $this->query('x');
        $rows       = [
            new VaultItem(VaultItemType::Login, 'upper', 'UserA', 'ciphertext'),
            new VaultItem(VaultItemType::Login, 'padded', 'usera ', 'ciphertext'),
            new VaultItem(VaultItemType::Login, 'exact', 'usera', 'ciphertext'),
            new stdClass(),
        ];

        self::assertSame(['exact'], array_map(static fn (VaultItem $item): string => $item->getTitle(), $definition->filterVisible($rows, 'usera', $query)));
        self::assertSame([], $definition->filterVisible($rows, 'UserA', $query), 'The tenant must be the viewer id, byte for byte.');
        self::assertSame([], $definition->filterVisible($rows, 'usera', $this->query('x', 10, null, self::VIEWER_B)), 'Another identifier.');
    }

    public function testTheViewerIsTheSignedInVaultUserOnly(): void
    {
        $marker = $this->marker();
        $this->seed(self::ALICE, $marker);
        $definition = $this->searchDefinition();

        $this->actAs(self::ALICE, self::VIEWER_A);
        self::assertSame(self::ALICE, $definition->tenant($this->query($marker)));
        self::assertNull($definition->tenant($this->query($marker, 10, null, self::VIEWER_B)), 'Another identifier.');
        self::assertFalse($definition->featureEnabled(self::BOB, $this->query($marker)), 'Another tenant.');
        self::assertSame([], $definition->baseQuery(self::BOB, $this->query($marker))->getQuery()->getResult(), 'A tenant that is not the viewer finds nothing.');

        $this->tokens->setToken(new UsernamePasswordToken(new OtherDomainUser(self::ALICE, self::VIEWER_A), 'main', []));
        self::assertNull($definition->tenant($this->query($marker)), 'A user of another identity domain is never a vault user.');

        foreach (["vtsk-al\u{200B}ice", 'vtsk-alice ', ''] as $unusable) {
            $this->tokens->setToken(new UsernamePasswordToken(new DddUser($unusable, self::VIEWER_A), 'main', []));
            self::assertNull($definition->tenant($this->query($marker)), json_encode($unusable, JSON_THROW_ON_ERROR));
        }
    }

    public function testAReferenceResolverThatDoesNotBindTheIdIsRefused(): void
    {
        // A resolver that maps by id but binds another value: the column would be compared with something else.
        $users = new class implements VaultUserReferenceResolverInterface {
            public function mapsById(): bool
            {
                return true;
            }

            public function isAccepted(object $user): bool
            {
                return true;
            }

            public function reference(object $user): object|string
            {
                return 'someone-else';
            }
        };
        $this->tokens->setToken(new UsernamePasswordToken(new DddUser(self::ALICE, self::VIEWER_A), 'main', []));

        self::assertNull((new VaultSearchViewers($users, $this->tokens))->forIdentifier(self::VIEWER_A));
        self::assertNull((new VaultSearchViewers(new DoctrineVaultUserReferenceResolver(DddUser::class, new VaultUserClassPolicy(DddUser::class, [], VaultUserMapping::ID), VaultUserMapping::ID)))->forIdentifier(self::VIEWER_A), 'No token storage.');
    }

    public function testRecentsAcceptTheItemRouteAndALowerCaseUuidOnly(): void
    {
        $definition = $this->searchDefinition();
        $id         = '0b9c8f3e-1d2a-4c5b-8e7f-6a5b4c3d2e1f';

        self::assertSame('i.id', $definition->idField());
        self::assertSame($id, $definition->referenceId(new SearchReference('/x', 'vault_items', 'nowo_vault_item_view', ['id' => $id])));
        self::assertNull($definition->referenceId(new SearchReference('/x', 'vault_items', 'nowo_vault_items', ['id' => $id])));
        self::assertNull($definition->referenceId(new SearchReference('/x', 'vault_items', 'nowo_vault_item_view', ['id' => '0B9C8F3E-1D2A-4C5B-8E7F-6A5B4C3D2E1F'])));
        self::assertNull($definition->referenceId(new SearchReference('/x', 'vault_items', 'nowo_vault_item_view', ['id' => $id . "\n"])));
        self::assertNull($definition->referenceId(new SearchReference('/x', 'vault_items', 'nowo_vault_item_view', ['id' => 5])));
        self::assertNull($definition->referenceId(new SearchReference('/x', 'vault_items', 'nowo_vault_item_view')));
    }

    public function testRoutesComeFromTheRuntimeConfiguration(): void
    {
        $marker = $this->marker();
        $id     = $this->seed(self::ALICE, $marker);
        $this->actAs(self::ALICE, self::VIEWER_A);
        $definition = $this->searchDefinition(self::hostRoutes());

        $item = $definition->toItem(self::em()->find(VaultItem::class, $id) ?? new stdClass(), $this->query($marker));
        self::assertSame('/host/secrets/' . $id, $item->url);
        self::assertNull($definition->seeAllUrl($this->query($marker)));
        self::assertNotNull($definition->referenceId(new SearchReference('/host/secrets/' . $id, 'vault_items', 'host_item_view', ['id' => $id])));
    }

    public function testQueryBudgetHoldsWithGrantsTeamsScopesAndFolders(): void
    {
        $marker = $this->marker();
        $folder = $this->persistFolder(new VaultFolder('Mine', self::ALICE));
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' own', self::ALICE, 'ciphertext', $folder));
        $shared = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' shared', self::BOB, 'ciphertext'));
        $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' scope', 'proj-a', null, 'ciphertext', $this->persistFolder(VaultFolder::forScope('Ops', 'proj-a', null))));
        $this->grant(VaultResourceType::Item, $shared, GranteeType::Team, 'team-1');
        $this->teamIds[self::ALICE] = ['team-1'];
        $this->members[self::ALICE] = ['proj-a' => new VaultScopeAccess(VaultPermission::Read)];
        $this->searchScopes(['proj-a']);
        $this->actAs(self::ALICE, self::VIEWER_A);
        $provider = $this->provider();

        $counter = $this->queryCounter();
        $counter->reset();
        $group = $provider->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $group);
        self::assertCount(3, $group->items);
        self::assertLessThanOrEqual(2, $counter->count(), implode("\n", $counter->queries()));
    }

    public function testDefinitionBoundsAndContract(): void
    {
        self::assertSame(1000, $this->searchDefinition(maxCandidates: 5000)->maxCandidates());
        self::assertSame(1, $this->searchDefinition(maxCandidates: 0)->maxCandidates());
        $definition = $this->searchDefinition();
        self::assertSame(EntitySearchDefinition::DEFAULT_MAX_CANDIDATES, $definition->maxCandidates());
        self::assertSame('vault_items', $definition->group());
        self::assertSame('vault_item', $definition->subjectType());
        self::assertTrue($definition->sensitive());
        self::assertSame([], $definition->filterVisible([], self::ALICE, $this->query('x')), 'No viewer.');

        $this->expectException(LogicException::class);
        $definition->toItem(new stdClass(), $this->query('x'));
    }

    public function testWithoutTranslatorTheLabelsAreEnglishAndRaw(): void
    {
        $marker = $this->marker();
        $id     = $this->seed(self::ALICE, $marker);
        $this->actAs(self::ALICE, self::VIEWER_A);
        $definition = $this->searchDefinition(translator: null);

        self::assertSame('Vault items', $definition->label($this->query($marker)));
        self::assertSame('login', $definition->toItem(self::em()->find(VaultItem::class, $id) ?? new stdClass(), $this->query($marker))->subtitle);
    }

    /**
     * Audit VJ-1: rows the viewer may not see, all NEWER than the visible ones, never take a slot of a small candidate
     * window: non-member scopes (named or not), a grants-only member's ungranted items, a shared folder holding another
     * user's items, expired grants, items in the trash. The visible count, the total and its exactness do not move.
     */
    public function testHiddenRowsNewerThanTheVisibleOnesNeverFillTheWindow(): void
    {
        $marker = $this->marker();
        for ($row = 0; $row < 3; ++$row) {
            $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' divorce ' . $row, self::ALICE, 'ciphertext'));
        }
        $this->actAs(self::ALICE, self::VIEWER_A);
        $definition = $this->searchDefinition(maxCandidates: 5);
        $before     = (new EntitySearchProvider($definition))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $before);
        self::assertSame(3, $before->total);
        self::assertTrue($before->exactTotal);

        $bobFolder = $this->persistFolder(new VaultFolder('Bob shared', self::BOB));
        $this->grant(VaultResourceType::Folder, $bobFolder->getId(), GranteeType::User, self::ALICE);
        for ($row = 0; $row < 7; ++$row) {
            $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' divorce foreign ' . $row, 'proj-x', null, 'ciphertext'));
            $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' divorce named ' . $row, 'proj-named', null, 'ciphertext'));
            $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' divorce grants-only ' . $row, 'proj-g', null, 'ciphertext'));
            $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' divorce carol ' . $row, 'vtsk-carol', 'ciphertext', $bobFolder));
            $expired = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' divorce expired ' . $row, self::BOB, 'ciphertext'));
            $this->grant(VaultResourceType::Item, $expired, GranteeType::User, self::ALICE, new DateTimeImmutable('-1 minute'));
            $lookAlike = $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' divorce lookalike ' . $row, 'VTSK-ALICE', 'ciphertext'));
            $this->grant(VaultResourceType::Item, $lookAlike, GranteeType::User, 'vtsk-alice ');
            $this->persistItem((new VaultItem(VaultItemType::Login, $marker . ' divorce trash ' . $row, self::ALICE, 'ciphertext'))->markDeleted());
        }
        $this->members[self::ALICE] = ['proj-g' => new VaultScopeAccess(null)];
        $this->searchScopes(['proj-named', 'proj-g']);
        $this->actAs(self::ALICE, self::VIEWER_A);

        $after = (new EntitySearchProvider($definition))->search($this->query($marker));
        self::assertInstanceOf(SearchGroup::class, $after);
        self::assertEqualsCanonicalizing($this->titles($before), $this->titles($after));
        self::assertSame(3, $after->total);
        self::assertTrue($after->exactTotal);
        self::assertCount(3, $definition->baseQuery(self::ALICE, $this->query($marker))->andWhere('i.title LIKE :t')->setParameter('t', $marker . '%')->getQuery()->getResult(), 'Not even a SQL candidate.');
    }

    /**
     * Audit VJ-2: the host scope resolver is asked only about the scopes a SEARCH_SCOPES listener names, once per
     * request, never about the scope of a row.
     */
    public function testTheScopeResolverIsAskedOnlyAboutNamedScopes(): void
    {
        $marker = $this->marker();
        foreach (['proj-a', 'proj-x', 'proj-y', 'proj-z'] as $scope) {
            for ($row = 0; $row < 3; ++$row) {
                $this->persistItem(VaultItem::forScope(VaultItemType::Login, $marker . ' ' . $scope . ' ' . $row, $scope, null, 'ciphertext'));
            }
        }
        $this->members[self::ALICE] = ['proj-a' => new VaultScopeAccess(VaultPermission::Read), 'proj-x' => new VaultScopeAccess(VaultPermission::Read)];
        $this->persistItem(new VaultItem(VaultItemType::Login, $marker . ' own', self::ALICE, 'ciphertext'));

        $this->actAs(self::ALICE, self::VIEWER_A);
        $this->resolverCalls = 0;
        self::assertSame([$marker . ' own'], $this->titles($this->provider()->search($this->query($marker))));
        self::assertSame(0, $this->resolverCalls, 'No listener: the resolver is never asked.');

        $this->searchScopes(['proj-a']);
        $this->actAs(self::ALICE, self::VIEWER_A);
        $this->resolverCalls = 0;
        $group = $this->provider()->search($this->query($marker));
        self::assertCount(4, $this->titles($group));
        self::assertSame(1, $this->resolverCalls, 'Once for the named scope, then memoized for the rows.');
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        return $group instanceof SearchGroup ? array_map(static fn (SearchItem $row): string => $row->title, $group->items) : [];
    }
}
