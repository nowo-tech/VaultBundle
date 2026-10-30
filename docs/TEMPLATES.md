# Templates

The manage Web UI follows the UiKit page contract (REQ-UI-003): every page extends a UiKit scaffold
(`@NowoUiKit/page/*.html.twig`), gets a view model from `Nowo\VaultBundle\Ui\VaultPageFactory` and fills only the
data blocks of its scaffold. Chrome (layout, page header, flashes, filters frame, table, row actions, confirm dialogs,
pagination, empty states, dialogs) comes from UiKit and is themed once in `nowo_ui_kit.theme`; the bundle ships no
local layout, page header, flash, pagination, confirm or modal partials. Bundle markup uses theme slots
(`nowo_ui_class()` / `nowo_ui_attrs()`) and `vault-*` layout hooks (`vault.css`) only.

The contract is locked by `tests/Integration/Contract/PageContractTest.php` (UiKit `PageContractTestCase`, strict
missing-translation rule), which renders every page below in its significant states.

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Partials](#partials)
- [Layout](#layout)
- [Per-page overrides](#per-page-overrides)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Vault home | `vault/home.html.twig` | `page/dashboard` | `DashboardPage` (header `vault.title`; action Open settings for admins when `config_storage.enabled`; back link to `dashboard_route`) | `nowo_ui_dashboard_cards` (My items / Shared / Trash, create an entry per item type, password generator), `nowo_ui_modals` (generator dialog) |
| My items | `vault/items.html.twig` | `page/list` | `ListPage` (header actions: one create action per item type; filters: `VaultItemSearchType`; columns: type, title, tags, folder; rowActions: view, share, edit, delete = trash; `Pagination`; `EmptyState`) | `nowo_ui_list_filters` (folders and tags panels, then the default search region), `nowo_ui_list_row` |
| Shared items | `vault/shared.html.twig` | `page/list` | `ListPage` (columns: type, title; rowActions as My items; `Pagination`; `EmptyState`) | `nowo_ui_list_row` |
| Trash | `vault/trash.html.twig` | `page/list` | `ListPage` (column: title; rowActions: restore, delete = purge behind a `Confirm`; `Pagination`; `EmptyState`) | — (default cells) |
| Item create / edit / view | `vault/item_form.html.twig` | `page/form` | `FormPage` (header: item type, intro = title; `VaultItemFormType`; submit `vault.action.save`; cancel = items list; read-only items: `formTag` false) | `nowo_ui_form_body` (per-item-type layout + child loop) |
| Share access | `vault/share.html.twig` | `page/form` | `FormPage` (`VaultShareType`; submit `vault.share.submit`) + `Table` of grants (`VaultPageFactory::grantsTable()`, row action delete = revoke behind a `Confirm`) | `nowo_ui_form_body`, `nowo_ui_form_aside` (current grants through the UiKit `table` partial) |
| Runtime configuration | `vault/runtime_config.html.twig` | `page/settings` | `SettingsPage` (one section `runtime` with `VaultRuntimeConfigType`; intro = last update) | `nowo_ui_settings_section` (encryption key states + child loop + submit) |

Destructive actions (move an item or a folder to the trash, delete a tag, purge, remove access) are POST forms with a
CSRF-only `VaultActionType` FormView behind the UiKit confirm `<dialog>` (`View\Confirm`); there is no inline
`confirm()` / `on*=` handler and no inline `<script>` (the generator endpoint and its CSRF token are data attributes of
the generator dialog).

### View-model fields used by the data blocks

| Page | Extra variables (besides `page`) |
| --- | --- |
| Vault home | `routes`, `itemTypes`, `generatorForm`, `dashboardRoute` |
| My items | `routes`, `items`, `total`, `readOnlyMap`, `grantCounts`, `folders`, `tags`, `folderActions` (folder id => `Action` list), `tagActions` (tag id => `Action`), `folderForm`, `activeFolder`, `activeTag`, `searchQuery` |
| Shared items | `routes`, `items`, `readOnlyMap` |
| Trash | `routes`, `items` |
| Item form | `form`, `itemType`, `item`, `readOnly`, `viewMode`, `folders`, `routes`, `tagInputEnabled`, `passwordStrengthEnabled`, `activeFolder` |
| Share access | `grantsTable`, `grants`, `granteeLabels`, `resourceLabel`, `routes` |
| Runtime configuration | `routes`, `runtimeConfigRoute`, `dbUpdatedAt`, `hasDbEncryptionKey`, `hasYamlEncryptionKey`, `canStoreEncryptionKey`, `showOneTimeKeyWarning`, `form` |

Every page also receives `nowo_vault_legacy_layout` / `nowo_vault_legacy_blocks` (the deprecated 1.x layout bridge).

## Unique pages

None: every page extends a scaffold.

## Partials

| Partial | Role |
| --- | --- |
| `vault/_password_generator.html.twig` | Password generator body, rendered through the UiKit `dialog` partial (`<dialog id="vault-password-generator">`) |
| `vault/_legacy_layout.html.twig` | Deprecated bridge around a 1.x layout without the UiKit canonical blocks (removed in 2.0) |
| `Form/vault_item_form_theme.html.twig` | Tag container of the item form when `nowo-tech/tag-input-bundle` is installed (`FormPage::formThemes`) |

## Layout

`nowo_vault.web_ui.layout_template` (default `null`) is passed to the scaffolds as `page.layout`; null uses
`nowo_ui_kit.layouts.admin`. A layout that does not define the UiKit canonical blocks (a 1.x layout rendering
`{% block body %}`) is wrapped in `vault/_legacy_layout.html.twig` with a deprecation.

## Per-page overrides

`nowo_vault.web_ui.templates.<page>` (`home`, `items`, `item_form`, `trash`, `shared`, `share`, `runtime_config`): a
host template that extends `@!NowoVaultBundle/vault/<page>.html.twig` and overrides one data block, for example:

```twig
{# templates/vault/items.html.twig — nowo_vault.web_ui.templates.items: 'vault/items.html.twig' #}
{% extends '@!NowoVaultBundle/vault/items.html.twig' %}

{% block nowo_ui_list_after %}
    <p class="{{ nowo_ui_class('muted', page.bundle) }}">{{ 'app.vault.items_footer'|trans }}</p>
{% endblock %}
```

Re-skin every list / form / settings page of every bundle once by overriding the UiKit scaffolds in
`templates/bundles/NowoUiKitBundle/page/`, or remap the theme slots and tokens in `nowo_ui_kit.theme`
(per-bundle exceptions: `nowo_ui_kit.theme.bundles.NowoVaultBundle`).

## Allowed exceptions

- (none) — `PageContractTest` uses the default class allow-list of UiKit.
- Optional integrations keep their own widget options: `nowo-tech/password-strength-bundle` (`ui_framework`) and
  `nowo-tech/tag-input-bundle` (`input_class: nowo-ui-input`) render their own widgets; they are not exercised by the
  contract test (the integrations are optional).
