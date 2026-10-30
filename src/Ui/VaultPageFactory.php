<?php

declare(strict_types=1);

namespace Nowo\VaultBundle\Ui;

use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\DashboardPage;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FormPage;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Nowo\UiKitBundle\View\Table;
use Nowo\VaultBundle\Entity\VaultFolder;
use Nowo\VaultBundle\Entity\VaultGrant;
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Entity\VaultTag;
use Nowo\VaultBundle\Enum\VaultItemType;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_filter;
use function ceil;
use function max;

/**
 * Builds the UiKit view models of the manage pages (REQ-UI-003): page headers (title, intro, actions, back link),
 * columns, row actions (POST + CSRF-only FormViews; trash / purge / revoke / delete behind a Confirm), pagination,
 * empty states, forms and the runtime settings section. The controllers and the page contract test share it, so the
 * test renders exactly the view models the application gets. Access decisions stay in the controllers: the factory
 * only renders the actions it is given forms for. Stateless: safe in worker mode.
 *
 * `$routes` is the resolved `nowo_vault.routes` map (route key => {path, name}).
 */
final readonly class VaultPageFactory
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private VaultPages $pages = new VaultPages(),
    ) {
    }

    /**
     * Page variables plus the shared ones (legacy layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    /**
     * Vault landing page: sections, item types and the password generator are cards of the dashboard; the runtime
     * settings action appears for admins when the database-backed configuration is enabled.
     *
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function home(array $routes, ?string $runtimeConfigRoute, ?string $dashboardRoute): DashboardPage
    {
        $actions = [];
        if ($runtimeConfigRoute !== null) {
            $actions[] = new Action('config', VaultPages::label('vault.action.open_settings'), $this->url($runtimeConfigRoute), icon: 'config', variant: 'secondary', attributes: ['data-testid' => 'vault-runtime-config-link']);
        }

        return new DashboardPage(
            header: new PageHeader(
                VaultPages::label('vault.title'),
                VaultPages::label('vault.home.intro'),
                $actions,
                back: $this->dashboardBack($dashboardRoute),
            ),
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-home'],
        );
    }

    /**
     * Items list: one create action per item type (kept in the active folder), search filter, item cells, row
     * actions View / Access / Edit / Trash (the last three only for items the user may change).
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<VaultItem> $items
     * @param array<string, bool> $readOnlyMap item id => read-only for the current user
     * @param array<string, FormView> $trashForms item id => CSRF-only trash form
     */
    public function items(
        array $routes,
        array $items,
        array $readOnlyMap,
        array $trashForms,
        FormView $searchForm,
        ?Pagination $pagination,
        ?string $activeFolder,
        ?string $dashboardRoute,
    ): ListPage {
        $create = [];
        foreach (VaultItemType::cases() as $type) {
            $create[] = new Action(
                'create',
                VaultPages::label('vault.type.' . $type->value),
                $this->url($routes['item_new']['name'], array_filter(['type' => $type->value, 'folder' => $activeFolder], static fn (?string $v): bool => $v !== null && $v !== '')),
                icon: 'create',
                variant: 'secondary',
                attributes: ['data-testid' => 'vault-new-' . $type->value],
            );
        }

        return new ListPage(
            header: new PageHeader(
                VaultPages::label('vault.home.items.title'),
                VaultPages::label('vault.home.items.description'),
                $create,
                back: $this->homeBack($routes, $dashboardRoute),
            ),
            columns: [
                new Column('type', VaultPages::label('vault.list.type'), priority: 2),
                new Column('title', VaultPages::label('vault.list.title'), required: true),
                new Column('tags', VaultPages::label('vault.form.tags'), priority: 3),
                new Column('folder', VaultPages::label('vault.list.folder'), priority: 2),
            ],
            rows: $items,
            filters: $searchForm,
            rowActions: fn (VaultItem $item): array => $this->itemActions($routes, $item, $readOnlyMap, $trashForms),
            pagination: $pagination,
            empty: new EmptyState(null, VaultPages::label('vault.empty.items')),
            filtersClearHref: $this->url($routes['items']['name'], array_filter(['folder' => $activeFolder], static fn (?string $v): bool => $v !== null && $v !== '')),
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-items'],
            actionsLabel: VaultPages::label('vault.list.actions'),
            filtersSubmit: new Action('filter', VaultPages::label('vault.action.search'), icon: 'filter'),
            filtersClear: new Action('cancel', VaultPages::label('vault.search.clear')),
        );
    }

    /**
     * Folder actions of the items list: Share and Trash (POST + CSRF behind a Confirm that counts the items it
     * detaches).
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<VaultFolder> $folders
     * @param array<string, FormView> $trashForms folder id => CSRF-only trash form
     * @param array<string, int> $itemCounts folder id => active items
     *
     * @return array<string, list<Action>> folder id => actions
     */
    public function folderActions(array $routes, array $folders, array $trashForms, array $itemCounts): array
    {
        $actions = [];
        foreach ($folders as $folder) {
            $id    = $folder->getId();
            $count = $itemCounts[$id] ?? 0;

            $actions[$id] = [
                new Action('share', VaultPages::label('vault.share.manage'), $this->url($routes['folder_share']['name'], ['id' => $id]), icon: 'share', display: 'icon'),
                new Action(
                    'delete',
                    VaultPages::label('vault.action.trash'),
                    $this->url($routes['folder_trash']['name'], ['id' => $id]),
                    method: 'POST',
                    confirm: new Confirm(
                        VaultPages::label('vault.confirm.trash_folder_title', ['%name%' => $folder->getName()]),
                        $count > 0 ? VaultPages::label('vault.confirm.trash_folder_with_items', ['%count%' => $count]) : VaultPages::label('vault.confirm.trash_folder'),
                        VaultPages::label('vault.action.trash'),
                        cancelLabel: VaultPages::label('vault.action.cancel'),
                    ),
                    icon: 'delete',
                    variant: 'danger',
                    form: $trashForms[$id] ?? null,
                    display: 'icon',
                ),
            ];
        }

        return $actions;
    }

    /**
     * Tag delete actions of the items list (POST + CSRF behind a Confirm; the form keeps the list filters).
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<VaultTag> $tags
     * @param array<string, FormView> $deleteForms tag id => CSRF-only delete form
     *
     * @return array<string, Action> tag id => action
     */
    public function tagActions(array $routes, array $tags, array $deleteForms): array
    {
        $actions = [];
        foreach ($tags as $tag) {
            $id           = $tag->getId();
            $actions[$id] = new Action(
                'delete',
                VaultPages::label('vault.action.delete_tag'),
                $this->url($routes['tag_delete']['name'], ['id' => $id]),
                method: 'POST',
                confirm: new Confirm(
                    VaultPages::label('vault.action.delete_tag'),
                    VaultPages::label('vault.confirm.delete_tag', ['%name%' => $tag->getName()]),
                    VaultPages::label('vault.action.delete_tag'),
                    cancelLabel: VaultPages::label('vault.action.cancel'),
                ),
                icon: 'delete',
                variant: 'danger',
                form: $deleteForms[$id] ?? null,
                display: 'icon',
            );
        }

        return $actions;
    }

    /**
     * Items shared with the user: same row actions as the items list.
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<VaultItem> $items
     * @param array<string, bool> $readOnlyMap
     * @param array<string, FormView> $trashForms
     */
    public function shared(array $routes, array $items, array $readOnlyMap, array $trashForms, Pagination $pagination, ?string $dashboardRoute): ListPage
    {
        return new ListPage(
            header: new PageHeader(
                VaultPages::label('vault.nav.shared'),
                VaultPages::label('vault.shared.hint'),
                back: $this->homeBack($routes, $dashboardRoute),
            ),
            columns: [
                new Column('type', VaultPages::label('vault.list.type'), priority: 2),
                new Column('title', VaultPages::label('vault.list.title'), required: true),
            ],
            rows: $items,
            rowActions: fn (VaultItem $item): array => $this->itemActions($routes, $item, $readOnlyMap, $trashForms),
            pagination: $pagination,
            empty: new EmptyState(null, VaultPages::label('vault.empty.shared')),
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-shared'],
            actionsLabel: VaultPages::label('vault.list.actions'),
        );
    }

    /**
     * Trash: Restore (POST + CSRF) and Purge (POST + CSRF behind a Confirm) per item.
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<VaultItem> $items
     * @param array<string, FormView> $restoreForms
     * @param array<string, FormView> $purgeForms
     */
    public function trash(array $routes, array $items, array $restoreForms, array $purgeForms, Pagination $pagination, ?string $dashboardRoute): ListPage
    {
        $actions = [];
        foreach ($items as $item) {
            $id           = $item->getId();
            $actions[$id] = [
                new Action('restore', VaultPages::label('vault.action.restore'), $this->url($routes['item_restore']['name'], ['id' => $id]), method: 'POST', icon: 'restore', form: $restoreForms[$id] ?? null),
                Action::delete(
                    $this->url($routes['item_purge']['name'], ['id' => $id]),
                    confirm: new Confirm(
                        VaultPages::label('vault.confirm.purge_title', ['%name%' => $item->getTitle()]),
                        VaultPages::label('vault.confirm.purge'),
                        VaultPages::label('vault.action.purge'),
                        cancelLabel: VaultPages::label('vault.action.cancel'),
                    ),
                    label: VaultPages::label('vault.action.purge'),
                    form: $purgeForms[$id] ?? null,
                ),
            ];
        }

        return new ListPage(
            header: new PageHeader(
                VaultPages::label('vault.nav.trash'),
                VaultPages::label('vault.home.trash.description'),
                back: $this->homeBack($routes, $dashboardRoute),
            ),
            columns: [new Column('title', VaultPages::label('vault.list.title'), required: true)],
            rows: $items,
            rowActions: static fn (VaultItem $item): array => $actions[$item->getId()] ?? [],
            pagination: $pagination,
            empty: new EmptyState(null, VaultPages::label('vault.empty.trash')),
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-trash'],
            actionsLabel: VaultPages::label('vault.list.actions'),
        );
    }

    /**
     * Item create / edit / view. Read-only items (view mode or a read grant) render without the submit row: the form
     * tag is printed by the page (formTag false) so an attempted POST still reaches the controller's access check.
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param list<string> $formThemes
     */
    public function itemForm(array $routes, FormView $form, VaultItemType $type, ?VaultItem $item, bool $readOnly, ?string $activeFolder, array $formThemes = []): FormPage
    {
        $backUrl = $this->url($routes['items']['name'], array_filter(['folder' => $activeFolder], static fn (?string $v): bool => $v !== null && $v !== ''));

        return new FormPage(
            header: new PageHeader(
                VaultPages::label('vault.type.' . $type->value),
                $item?->getTitle(),
                back: new BackLink($backUrl, VaultPages::label('vault.action.back'), 'vault-back'),
            ),
            form: $form,
            submitLabel: VaultPages::label('vault.action.save'),
            cancelHref: $backUrl,
            cancelLabel: VaultPages::label('vault.action.back'),
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-item-form'],
            formThemes: $formThemes,
            formTag: !$readOnly,
            formAttributes: ['class' => 'vault-form', 'data-vault-item-form' => $type->value, 'data-vault-readonly' => '0'],
        );
    }

    /**
     * Share page of an item or folder: the grant form; the existing grants come from {@see self::grantsTable()}.
     *
     * @param array<string, mixed> $backParams
     */
    public function share(FormView $form, string $resourceLabel, string $backRoute, array $backParams): FormPage
    {
        $backUrl = $this->url($backRoute, $backParams);

        return new FormPage(
            header: new PageHeader(
                VaultPages::label('vault.share.title'),
                $resourceLabel,
                back: new BackLink($backUrl, VaultPages::label('vault.action.back'), 'vault-back'),
            ),
            form: $form,
            submitLabel: VaultPages::label('vault.share.submit'),
            cancelHref: $backUrl,
            cancelLabel: VaultPages::label('vault.action.back'),
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-share'],
            formAttributes: ['class' => 'vault-form'],
        );
    }

    /**
     * Existing grants of the share page (rendered in the aside column through the UiKit `table` partial).
     *
     * @param list<VaultGrant> $grants
     * @param array<string, FormView> $revokeForms grant id => CSRF-only revoke form
     */
    public function grantsTable(array $grants, array $revokeForms, string $resourceId, string $revokeRoute): Table
    {
        $actions = [];
        foreach ($grants as $grant) {
            $id           = $grant->getId();
            $actions[$id] = [new Action(
                'delete',
                VaultPages::label('vault.action.revoke_access'),
                $this->url($revokeRoute, ['id' => $resourceId, 'grantId' => $id]),
                method: 'POST',
                confirm: new Confirm(
                    VaultPages::label('vault.action.revoke_access'),
                    VaultPages::label('vault.confirm.revoke_access'),
                    VaultPages::label('vault.action.revoke_access'),
                    cancelLabel: VaultPages::label('vault.action.cancel'),
                ),
                icon: 'delete',
                variant: 'danger',
                form: $revokeForms[$id] ?? null,
            )];
        }

        return new Table(
            columns: [
                new Column('granteeType', VaultPages::label('vault.share.grantee_type')),
                new Column('granteeId', VaultPages::label('vault.share.grantee_id'), required: true),
                new Column('permission', VaultPages::label('vault.share.permission')),
            ],
            rows: $grants,
            rowActions: static fn (VaultGrant $grant): array => $actions[$grant->getId()] ?? [],
            caption: VaultPages::label('vault.share.existing'),
            actionsLabel: VaultPages::label('vault.list.actions'),
            responsive: 'cards',
            bundle: VaultPages::BUNDLE,
            attributes: ['data-testid' => 'vault-grants'],
        );
    }

    /**
     * Runtime configuration screen: one settings section with the encryption key / attachment size / password level
     * form (the section block renders the encryption key states).
     */
    public function runtimeConfig(FormView $form, ?string $lastUpdate, ?string $dashboardRoute): SettingsPage
    {
        return new SettingsPage(
            header: new PageHeader(
                VaultPages::label('vault.runtime_config.title'),
                VaultPages::label('vault.runtime_config.intro'),
                back: $this->dashboardBack($dashboardRoute),
            ),
            sections: [new SettingsSection(
                'runtime',
                VaultPages::label('vault.runtime_config.section'),
                form: $form,
                intro: $lastUpdate !== null ? VaultPages::label('vault.runtime_config.last_update', ['%datetime%' => $lastUpdate]) : null,
                submitLabel: VaultPages::label('vault.action.save'),
            )],
            current: 'runtime',
            bundle: VaultPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'vault-runtime-config'],
        );
    }

    /**
     * Pager of the items / shared / trash lists (REQ-PERF-001); null values of `$query` are dropped.
     *
     * @param array<string, string|null> $query
     */
    public function pagination(string $route, int $page, int $perPage, int $total, array $query = []): Pagination
    {
        return new Pagination(
            $page,
            $perPage,
            $total,
            max(1, (int) ceil($total / $perPage)),
            $route,
            array_filter($query, static fn (?string $value): bool => $value !== null && $value !== ''),
        );
    }

    /**
     * Row actions of an item: View, then Access / Edit / Trash when the user may change it.
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param array<string, bool> $readOnlyMap
     * @param array<string, FormView> $trashForms
     *
     * @return list<Action>
     */
    private function itemActions(array $routes, VaultItem $item, array $readOnlyMap, array $trashForms): array
    {
        $id      = $item->getId();
        $actions = [Action::view($this->url($routes['item_view']['name'], ['id' => $id]), VaultPages::label('vault.action.view'))];
        if ($readOnlyMap[$id] ?? false) {
            return $actions;
        }

        $actions[] = new Action('share', VaultPages::label('vault.action.access'), $this->url($routes['item_share']['name'], ['id' => $id]), icon: 'share');
        $actions[] = Action::edit($this->url($routes['item_edit']['name'], ['id' => $id]), VaultPages::label('vault.action.edit'));
        if (isset($trashForms[$id])) {
            $actions[] = Action::delete(
                $this->url($routes['item_trash']['name'], ['id' => $id]),
                confirm: new Confirm(
                    VaultPages::label('vault.confirm.trash_title', ['%name%' => $item->getTitle()]),
                    VaultPages::label('vault.confirm.trash'),
                    VaultPages::label('vault.action.trash'),
                    cancelLabel: VaultPages::label('vault.action.cancel'),
                ),
                label: VaultPages::label('vault.action.trash'),
                form: $trashForms[$id],
            );
        }

        return $actions;
    }

    /**
     * Back link of the list pages: the host dashboard when configured, else the vault home.
     *
     * @param array<string, array{path: string, name: string}> $routes
     */
    private function homeBack(array $routes, ?string $dashboardRoute): BackLink
    {
        return $dashboardRoute !== null
            ? new BackLink($this->url($dashboardRoute), VaultPages::label('vault.action.back'), 'vault-back')
            : new BackLink($this->url($routes['index']['name']), VaultPages::label('vault.title'), 'vault-back');
    }

    private function dashboardBack(?string $dashboardRoute): ?BackLink
    {
        return $dashboardRoute !== null ? new BackLink($this->url($dashboardRoute), VaultPages::label('vault.action.back'), 'vault-back') : null;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function url(string $route, array $params = []): string
    {
        return $this->urlGenerator->generate($route, $params);
    }
}
