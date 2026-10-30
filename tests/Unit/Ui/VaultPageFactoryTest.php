<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\GranteeType;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Enum\VaultPermission;
use Nowo\VaultBundle\Enum\VaultResourceType;
use Nowo\VaultBundle\Tests\Stub\TestUser;
use Nowo\VaultBundle\Tests\Support\PageFixtures;
use Nowo\VaultBundle\Ui\VaultPageFactory;
use Nowo\VaultBundle\Ui\VaultPages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormView;

use function array_map;
use function array_values;
use function count;

/**
 * View models of the manage pages: actions only for what the controllers allow, POST + Confirm on every destructive
 * action, back links, pagination query.
 */
final class VaultPageFactoryTest extends TestCase
{
    private VaultPageFactory $factory;

    protected function setUp(): void
    {
        $this->factory = PageFixtures::factory();
    }

    public function testHomeShowsTheSettingsActionOnlyForAdminsAndTheDashboardBackLink(): void
    {
        $admin = $this->factory->home(PageFixtures::routes(), 'nowo_vault_runtime_config', null);
        self::assertCount(1, $admin->header->actions);
        self::assertSame('/nowo_vault_runtime_config', $admin->header->actions[0]->href);
        self::assertNull($admin->header->back);
        self::assertSame(VaultPages::BUNDLE, $admin->bundle);

        $user = $this->factory->home(PageFixtures::routes(), null, 'app_dashboard');
        self::assertSame([], $user->header->actions);
        self::assertSame('/app_dashboard', $user->header->back?->href);
    }

    public function testItemsRowActionsFollowTheReadOnlyMapAndTheTrashForms(): void
    {
        $user     = new TestUser();
        $own      = new VaultItem(VaultItemType::Login, 'Own', $user, 'c');
        $readOnly = new VaultItem(VaultItemType::Login, 'Read only', $user, 'c');
        $noForm   = new VaultItem(VaultItemType::Login, 'No form', $user, 'c');
        $page     = $this->factory->items(
            PageFixtures::routes(),
            [$own, $readOnly, $noForm],
            [$readOnly->getId() => true],
            [$own->getId() => new FormView()],
            new FormView(),
            null,
            'folder-1',
            'app_dashboard',
        );

        self::assertCount(count(VaultItemType::cases()), $page->header->actions);
        self::assertSame('/nowo_vault_item_new?type=login&folder=folder-1', $page->header->actions[0]->href);
        self::assertSame('/app_dashboard', $page->header->back?->href);
        self::assertSame('/nowo_vault_items?folder=folder-1', $page->filtersClearHref);
        self::assertSame(['view', 'share', 'edit', 'delete'], $this->kinds($page->rowActions($own, 0)));
        self::assertSame(['view'], $this->kinds($page->rowActions($readOnly, 1)));
        self::assertSame(['view', 'share', 'edit'], $this->kinds($page->rowActions($noForm, 2)));

        $trash = $page->rowActions($own, 0)[3];
        self::assertTrue($trash->isPost());
        self::assertNotNull($trash->confirm);
        self::assertSame('/nowo_vault_item_trash?id=' . $own->getId(), $trash->href);

        $noFolder = $this->factory->items(PageFixtures::routes(), [], [], [], new FormView(), null, null, null);
        self::assertSame('/nowo_vault_index', $noFolder->header->back?->href);
        self::assertSame('/nowo_vault_items', $noFolder->filtersClearHref);
    }

    public function testFolderAndTagActionsArePostsBehindAConfirm(): void
    {
        $user    = new TestUser();
        $full    = new VaultFolder('Full', $user);
        $empty   = new VaultFolder('Empty', $user);
        $tag     = new VaultTag('bank', $user);
        $folders = $this->factory->folderActions(PageFixtures::routes(), [$full, $empty], [$full->getId() => new FormView()], [$full->getId() => 3]);

        self::assertSame(['share', 'delete'], $this->kinds($folders[$full->getId()]));
        self::assertTrue($folders[$full->getId()][1]->isPost());
        self::assertInstanceOf(FormView::class, $folders[$full->getId()][1]->form);
        self::assertSame('vault.confirm.trash_folder_with_items', $this->labelKey($folders[$full->getId()][1]->confirm?->message));
        self::assertSame('vault.confirm.trash_folder', $this->labelKey($folders[$empty->getId()][1]->confirm?->message));
        self::assertNull($folders[$empty->getId()][1]->form);

        $tags = $this->factory->tagActions(PageFixtures::routes(), [$tag], []);
        self::assertTrue($tags[$tag->getId()]->isPost());
        self::assertSame('/nowo_vault_tag_delete?id=' . $tag->getId(), $tags[$tag->getId()]->href);
    }

    public function testSharedAndTrashPages(): void
    {
        $user  = new TestUser();
        $item  = new VaultItem(VaultItemType::Login, 'Old', $user, 'c');
        $pager = $this->factory->pagination('nowo_vault_trash', 2, 10, 25, ['folder' => null, 'tag' => '', 'q' => 'x']);
        self::assertSame(3, $pager->totalPages);
        self::assertSame(['q' => 'x'], $pager->query);

        $shared = $this->factory->shared(PageFixtures::routes(), [$item], [$item->getId() => true], [], $pager, null);
        self::assertSame(['view'], $this->kinds($shared->rowActions($item, 0)));

        $trash   = $this->factory->trash(PageFixtures::routes(), [$item], [$item->getId() => new FormView()], [$item->getId() => new FormView()], $pager, null);
        $actions = $trash->rowActions($item, 0);
        self::assertSame(['restore', 'delete'], $this->kinds($actions));
        self::assertTrue($actions[0]->isPost());
        self::assertNull($actions[0]->confirm);
        self::assertNotNull($actions[1]->confirm);
        self::assertSame([], $trash->rowActions(new VaultItem(VaultItemType::Login, 'Other', $user, 'c'), 1));
    }

    public function testItemFormShareGrantsAndRuntimeConfig(): void
    {
        $user = new TestUser();
        $item = new VaultItem(VaultItemType::Login, 'GitHub', $user, 'c');

        $editable = $this->factory->itemForm(PageFixtures::routes(), new FormView(), VaultItemType::Login, $item, false, 'f-1', [VaultPages::ITEM_FORM_THEME]);
        self::assertTrue($editable->formTag);
        self::assertSame('GitHub', $editable->header->intro);
        self::assertSame('/nowo_vault_items?folder=f-1', $editable->cancelHref);
        self::assertSame([VaultPages::ITEM_FORM_THEME], $editable->formThemes);
        self::assertSame('login', $editable->formAttributes['data-vault-item-form']);

        $readOnly = $this->factory->itemForm(PageFixtures::routes(), new FormView(), VaultItemType::Login, null, true, null);
        self::assertFalse($readOnly->formTag);
        self::assertNull($readOnly->header->intro);

        $share = $this->factory->share(new FormView(), 'GitHub', 'nowo_vault_items', ['folder' => 'f-1']);
        self::assertSame('/nowo_vault_items?folder=f-1', $share->cancelHref);

        $grant  = new VaultGrant(VaultResourceType::Item, $item->getId(), GranteeType::User, 'u-2', VaultPermission::Read, $user);
        $table  = $this->factory->grantsTable([$grant], [], $item->getId(), 'nowo_vault_item_grant_revoke');
        $revoke = $table->rowActions($grant, 0);
        self::assertCount(1, $revoke);
        self::assertTrue($revoke[0]->isPost());
        self::assertNotNull($revoke[0]->confirm);
        self::assertSame([], $table->rowActions(new VaultGrant(VaultResourceType::Item, 'x', GranteeType::Team, 't', VaultPermission::Admin, $user), 1));

        $config = $this->factory->runtimeConfig(new FormView(), '2026-10-30 08:00:00 UTC', 'app_dashboard');
        self::assertSame('/app_dashboard', $config->header->back?->href);
        $sections = $config->sections;
        self::assertSame('vault.runtime_config.last_update', $this->labelKey($sections[0]->intro));
        self::assertNull($this->factory->runtimeConfig(new FormView(), null, null)->sections[0]->intro);

        self::assertSame(['page' => 1, 'nowo_vault_legacy_layout' => null, 'nowo_vault_legacy_blocks' => []], $this->factory->context(['page' => 1]));
    }

    /**
     * @param array<int|string, Action> $actions
     *
     * @return list<string>
     */
    private function kinds(array $actions): array
    {
        return array_values(array_map(static fn (Action $action): string => $action->kind, $actions));
    }

    private function labelKey(mixed $label): ?string
    {
        return $label instanceof Label ? $label->key : null;
    }
}
