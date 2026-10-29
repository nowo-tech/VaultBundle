# Usage

## Table of contents

- [Manage UI](#manage-ui)
- [Item types](#item-types)
- [Tags](#tags)
- [Folders](#folders)
- [Sharing](#sharing)
- [Read-only access](#read-only-access)
- [Events](#events)
- [Lists and pagination](#lists-and-pagination)
- [Twig overrides](#twig-overrides)
  - [Forms in templates (REQ-TWIG-005)](#forms-in-templates-req-twig-005)
- [Translations](#translations)

## Manage UI

Authenticated users open the vault at `/tools/vault` (configurable via `nowo_vault.routes`).

- **Vault** — own items, organized by folder
- **Shared items** — items granted via `VaultGrant` (user or team)
- **Trash** — soft-deleted items (restore or purge)
- **Password generator** — sidebar modal + inline on login forms

## Item types

| Type | Purpose |
|------|---------|
| `login` | Username, password, websites |
| `secure_note` | Encrypted note body |
| `credit_card` | Card number, CVV, expiry, PIN |
| `contact` | Name, email, phone, address |
| `id_card`, `drivers_license`, `passport`, `document` | Identity fields + optional file attachment |

Payloads are encrypted server-side with libsodium (`nowo_vault.encryption_key`).

## Tags

Assign one or more tags when creating or editing an item (one tag per field in the form). Tags are scoped to the item owner and reused across items with the same name. Delete your tags from the tag bar on the items list; items keep their other tags and simply lose the deleted label.

- Filter items by tag from the tag bar on the items list
- Search matches item titles **or** tag names (`q` query parameter)
- Tags are stored in `{table_prefix}_tags` and linked via `{table_prefix}_item_tag`

## Folders

Create folders from the sidebar. Share or trash folders via the folder row actions.

## Sharing

Use **Share** on an item (edit screen) or the folder share link. Grants support:

- **Grantee:** user or team/group id (`GranteeType::User` / `GranteeType::Team` — team and group are the same)
- **Permission:** `read`, `write`, or `admin`

Listen to `VaultEvents::GRANT_LIST_QUERY` to limit which users and teams appear in the share picker — see [Access control events](examples/AccessControl.md).

Team membership is resolved via `VaultTeamMembershipResolverInterface` (configure `nowo_vault.team_membership_resolver`).

## Read-only access

Listen to `VaultEvents::ITEM_READ_ONLY_RESOLVE` and call `$event->markReadOnly()` — see [examples/AccessControl.md](examples/AccessControl.md).

## Events

| Event | Purpose |
|-------|---------|
| `ITEM_LIST_QUERY` | Override list queries (shared, trash, search) |
| `ITEM_LIST_RESULT` | Filter results |
| `ITEM_ACCESS_CHECK` | Custom ACL / read-only |
| `ITEM_READ_ONLY_RESOLVE` | View-only mode |
| `FOLDER_ACCESS_CHECK` | Folder ACL |
| `GRANT_LIST_QUERY` | Limit users/teams (groups) in share UI for items and folders |

## Lists and pagination

The items, shared and trash pages are paginated (`?page=N`, `nowo_vault.pagination.per_page`, default 50; see [CONFIGURATION.md](CONFIGURATION.md#pagination)). Own / folder / tag / trash lists page in the database (Doctrine `Paginator`, tags fetch-joined, so the page costs a constant number of queries); shared items are loaded through the grant ids and sliced in PHP; search results stay capped at 50 and are not paginated. A custom `VaultItemRepositoryInterface` keeps working: implement `VaultItemPaginatorInterface` to page in the database, otherwise the lister slices the full list. Items returned by a `VaultEvents::ITEM_LIST_QUERY` override are used as given (your listener owns paging).

The browser extension `/logins` lookup decrypts at most `browser_extension.max_login_items` login items (default 1000, own items first, most recently updated first) and logs a warning when the limit truncates the scan.

## Twig overrides

The bundle registers **`@NowoVaultBundle/`**. Application files under **`templates/bundles/NowoVaultBundle/`** win over vendor (`TwigPathsPass`).

**Freeze rule:** an application file at the override path always wins and will **not** pick up vendor changes for that path until you remove or merge it. Prefer **`templates.layout`** + **`css_framework`** (and the per-page `templates.*` keys in [CONFIGURATION.md](CONFIGURATION.md#templates)) over copying every manage page.

Registration: `TwigPathsPass` prepends `templates/bundles/NowoVaultBundle/` (when it exists) and appends the bundle's `src/Resources/views` to the `NowoVaultBundle` namespace, so overrides are searched first. It is always active (the manage UI is not optional).

**Upgrade-safe customisation (preferred → last resort):**

| Depth | How | On a bundle update |
|-------|-----|--------------------|
| No Twig fork | `templates.layout` (host shell / bridge), `css_framework`, CSS on `nowo-ui-*` / `nowo-vault-*` classes | Every vendor template fix applies automatically |
| Surgical override | Copy one partial (e.g. `vault/_item_row_actions.html.twig`) or point a single `templates.<page>` key at your own file that `{% extends %}` the vendor page and overrides one block with `{{ parent() }}` | Only that file is frozen |
| Full-file override | Copy a page into `templates/bundles/NowoVaultBundle/<subpath>` | That `<subpath>` is frozen until you delete or merge it |

**Procedure**

1. Pick the `<subpath>` from the table below.
2. Create `templates/bundles/NowoVaultBundle/<subpath>` in the application (same relative path and filename).
3. Clear the cache in dev if needed: `php bin/console cache:clear`.
4. Treat the copy as a fork: it no longer receives vendor changes for that path.

**Recovering upstream after a freeze:** delete (or rename aside) the app override, clear the Twig cache, then re-apply only the surgical customisations you still need. Diff against the new vendor file under `vendor/nowo-tech/vault-bundle/src/Resources/views/` before re-copying.

**Overridable `<subpath>` values** (relative to `src/Resources/views`):

| `<subpath>` | Purpose |
|-------------|---------|
| `layout.html.twig` | Default outer HTML shell (`templates.layout`); replace via config rather than overriding |
| `vault/base.html.twig` | Manage base: flashes, page header, `body` block, `vault.css` / `vault.js` |
| `vault/home.html.twig` | Landing page (item types, password generator, runtime config link) |
| `vault/items.html.twig` | Items list with folders, tags, search and pager |
| `vault/shared.html.twig` | Items shared with the user (pager) |
| `vault/trash.html.twig` | Trashed items with restore / purge forms (pager) |
| `vault/item_form.html.twig` | Create / edit / view form of one item |
| `vault/share.html.twig` | Share form + existing grants of an item or folder |
| `vault/runtime_config.html.twig` | Database-backed runtime configuration screen |
| `vault/_flashes.html.twig` | Flash messages partial |
| `vault/_page_header.html.twig` | Page title / actions header partial |
| `vault/_item_row_actions.html.twig` | Row action buttons (view, edit, share, trash) |
| `vault/_grants_table.html.twig` | Grants table with revoke forms |
| `vault/_share_form_fields.html.twig` | Share form fields (grantee picker, permission) |
| `vault/_password_generator.html.twig` | Password generator widget |
| `vault/_pagination.html.twig` | Previous / next pager of the list pages |
| `Form/vault_item_form_theme.html.twig` | Form theme of the item form (per-type layouts) |

Renaming or removing a `<subpath>` or a stable `{% block %}` is treated as a breaking change and is listed in [UPGRADING.md](UPGRADING.md).

Manage pages extend `@NowoVaultBundle/vault/base.html.twig`, which stacks `vault.css` / `vault.js` with `{{ parent() }}` on `stylesheets` / `javascripts` (REQ-UI-001). Point `templates.layout` at the host layout (or a bridge) so chrome comes from the project without forking every page.

**`css_framework` (REQ-UI-001):** set root key `css_framework` to match the host stack. Twig global: `nowo_vault_css_framework` (default `tabler`, matching the demo CDN layout). Use `custom` / `none` when the host shell already provides CSS and you restyle via `nowo-ui-*` / Tabler-compatible classes. Example:

```yaml
nowo_vault:
    css_framework: tabler
    templates:
        layout: 'layouts/app.html.twig'
```

### Forms in templates (REQ-TWIG-005)

Every control in the manage views comes from a PHP Symfony Form Type (Form Kit `vault` profile) built by the controller and rendered with `form_start` / the child loop / `form_end`:

| View | Variable(s) | Form Type |
| ---- | ----------- | --------- |
| `vault/items.html.twig` | `searchForm`, `folderForm`, `tagDeleteForms`, `folderTrashForms`, `trashForms` | `VaultItemSearchType` (GET `q` + `folder` / `tag`), `VaultFolderCreateType` (`name`), `VaultActionType` |
| `vault/shared.html.twig`, `vault/_item_row_actions.html.twig` | `trashForms` (item id → form) | `VaultActionType` |
| `vault/trash.html.twig` | `restoreForms`, `purgeForms` | `VaultActionType` |
| `vault/share.html.twig`, `vault/_grants_table.html.twig` | `revokeGrantForms` (grant id → form) | `VaultActionType` |
| `vault/home.html.twig`, `vault/_password_generator.html.twig` | `generatorForm` | `VaultPasswordGeneratorType` (browser-only, never submitted) |
| `vault/item_form.html.twig` | `form.attachment` | `VaultItemFormType` (unmapped, posted as `attachment`) |
| `vault/runtime_config.html.twig` | `form` | `VaultRuntimeConfigType` |

Posted names and CSRF token ids are unchanged (flat `_token`, `vault_trash_<id>`, `vault_folder_create`, …); the controllers keep validating the token through `VaultCsrfTrait`, which also accepts the `X-CSRF-Token` header and JSON bodies used by API clients. Render form bodies with the canonical loop after any custom children:

```twig
{{ form_start(trash_form, {attr: {class: 'd-inline'}}) }}
    {% for child in trash_form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit" class="btn btn-sm btn-outline-danger">…</button>
{{ form_end(trash_form) }}
```

Template overrides and demos **must not** reintroduce raw `<form>`, `<input>`, `<textarea>` or `<select>` markup; `make check-no-raw-html-form` (part of `release-check`) fails when they do.

## Translations

Domain: `NowoVaultBundle`. Override in `translations/NowoVaultBundle.es.yaml`.
