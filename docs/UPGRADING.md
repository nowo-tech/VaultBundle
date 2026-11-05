# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.16.0](#to-1160)
  - [Schema](#schema)
  - [BC notes](#bc-notes)
  - [Configuration examples](#configuration-examples)
  - [New options (all off by default)](#new-options-all-off-by-default)
- [To 1.15.2](#to-1152)
- [To 1.15.1](#to-1151)
- [To 1.15.0](#to-1150)
  - [Template contract (REQ-UI-003)](#template-contract-req-ui-003)
  - [Configuration](#configuration)
  - [Security (fail closed)](#security-fail-closed)
- [To 1.14.0](#to-1140)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
- [To 1.11.1](#to-1111)
- [To 1.11.0](#to-1110)
- [To 1.10.0](#to-1100)
- [To 1.9.0](#to-190)
  - [Forms rendered through Form Types (REQ-TWIG-005)](#forms-rendered-through-form-types-req-twig-005)
- [To 1.8.2](#to-182)
- [To 1.8.1](#to-181)
- [To 1.8.0](#to-180)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.8](#to-148)
- [From 1.4.6 to 1.4.7](#from-146-to-147)
- [From 1.4.6 to 1.4.7](#from-146-to-147-1)
- [1.3.0 (2026-08-03)](#130-2026-08-03)
  - [Behaviour](#behaviour)
  - [Breaking changes](#breaking-changes)
- [1.2.0 (2026-07-30)](#120-2026-07-30)
  - [Behaviour](#behaviour-1)
- [1.1.5 (2026-07-29)](#115-2026-07-29)
- [1.1.4 (2026-07-22)](#114-2026-07-22)
- [1.1.3 (2026-07-16)](#113-2026-07-16)
- [1.1.2 (2026-07-13)](#112-2026-07-13)
- [1.1.1 (2026-07-08)](#111-2026-07-08)
- [1.1.0 (2026-07-05)](#110-2026-07-05)
  - [Summary](#summary)
  - [Upgrade steps](#upgrade-steps)
  - [CSRF on manage UI (breaking for custom integrations)](#csrf-on-manage-ui-breaking-for-custom-integrations)
  - [Browser extension (optional)](#browser-extension-optional)
  - [Runtime config (optional)](#runtime-config-optional)
  - [Encryption key rotation](#encryption-key-rotation)
  - [Template overrides](#template-overrides)
  - [Suggested packages](#suggested-packages)
- [1.0.0 (2026-07-04)](#100-2026-07-04)
  - [Upgrade steps](#upgrade-steps-1)
  - [Symfony 8 + Doctrine](#symfony-8--doctrine)
  - [Access control events](#access-control-events)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
- [Unreleased](#unreleased-1)
- [To 1.4.6](#to-146)
- [To 1.4.5](#to-145)
- [To 1.4.4](#to-144)
- [To 1.4.3](#to-143)
- [To 1.4.2](#to-142)
- [To 1.4.1](#to-141)
- [To 1.4.0](#to-140)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)

## Unreleased

## To 1.16.0

From **1.15.2** (minor):

```bash
composer update nowo-tech/vault-bundle
php bin/console assets:install
php bin/console doctrine:migrations:diff   # required: new columns, nullable owner FKs (see "Schema" below)
# add the two backfill UPDATEs below to the generated migration, then:
php bin/console doctrine:migrations:migrate
```

Every adopter must migrate, even without using the new features. With every new option left at its default, the
behaviour is 1.15.2's apart from the BC notes below.

### Schema

New columns (all additive) and nullable owner FKs, so that an item, folder or tag can be owned by a host **scope**
instead of a user:

| Table | Change |
| --- | --- |
| `vault_items` | `creator_id` nullable (still `ON DELETE CASCADE`); new `owner_scope` VARCHAR(128) NULL, `created_by_id` FK user NULL `ON DELETE SET NULL`, `secret_changed_at` DATETIME NULL, `catalogue_hidden` BOOLEAN NOT NULL DEFAULT false; indexes `vault_items_owner_scope_idx (owner_scope, deleted_at, updated_at)`, `vault_items_created_by_idx (created_by_id)` |
| `vault_folders` | `creator_id` nullable; new `owner_scope`, `created_by_id` (`SET NULL`); index `vault_folders_owner_scope_idx (owner_scope, deleted_at)` |
| `vault_tags` | `creator_id` nullable; new `owner_scope`; unique `vault_tags_scope_name_unique (owner_scope, name)` next to `(creator_id, name)` |
| `vault_grants` | new `expires_at` DATETIME NULL; indexes `vault_grants_expires_idx (expires_at)`, `vault_grants_grantee_idx (grantee_type, grantee_id)` (`created_by_id` already became `SET NULL` in 1.15.2) |

Reference DDL (default `table_prefix: vault_`; `<USER_ID_TYPE>` / `<user_table>` = your user entity's id type and
table, `<fk_…>` = the names Doctrine generated). The generated diff is the source of truth; it must contain this and
nothing else:

```sql
-- MySQL 8+ / MariaDB
ALTER TABLE vault_items
  MODIFY creator_id <USER_ID_TYPE> DEFAULT NULL,
  ADD owner_scope VARCHAR(128) DEFAULT NULL,
  ADD created_by_id <USER_ID_TYPE> DEFAULT NULL,
  ADD secret_changed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  ADD catalogue_hidden TINYINT(1) DEFAULT 0 NOT NULL;
ALTER TABLE vault_items ADD CONSTRAINT <fk_items_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
CREATE INDEX vault_items_owner_scope_idx ON vault_items (owner_scope, deleted_at, updated_at);
CREATE INDEX vault_items_created_by_idx ON vault_items (created_by_id);
ALTER TABLE vault_folders
  MODIFY creator_id <USER_ID_TYPE> DEFAULT NULL,
  ADD owner_scope VARCHAR(128) DEFAULT NULL,
  ADD created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_folders ADD CONSTRAINT <fk_folders_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL;
CREATE INDEX vault_folders_owner_scope_idx ON vault_folders (owner_scope, deleted_at);
ALTER TABLE vault_tags
  MODIFY creator_id <USER_ID_TYPE> DEFAULT NULL,
  ADD owner_scope VARCHAR(128) DEFAULT NULL;
CREATE UNIQUE INDEX vault_tags_scope_name_unique ON vault_tags (owner_scope, name);
ALTER TABLE vault_grants ADD expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)';
CREATE INDEX vault_grants_expires_idx ON vault_grants (expires_at);
CREATE INDEX vault_grants_grantee_idx ON vault_grants (grantee_type, grantee_id);

-- PostgreSQL
ALTER TABLE vault_items ALTER creator_id DROP NOT NULL;
ALTER TABLE vault_items ADD owner_scope VARCHAR(128) DEFAULT NULL;
ALTER TABLE vault_items ADD created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_items ADD secret_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
ALTER TABLE vault_items ADD catalogue_hidden BOOLEAN DEFAULT false NOT NULL;
ALTER TABLE vault_items ADD CONSTRAINT <fk_items_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
CREATE INDEX vault_items_owner_scope_idx ON vault_items (owner_scope, deleted_at, updated_at);
CREATE INDEX vault_items_created_by_idx ON vault_items (created_by_id);
ALTER TABLE vault_folders ALTER creator_id DROP NOT NULL;
ALTER TABLE vault_folders ADD owner_scope VARCHAR(128) DEFAULT NULL;
ALTER TABLE vault_folders ADD created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_folders ADD CONSTRAINT <fk_folders_created_by> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;
CREATE INDEX vault_folders_owner_scope_idx ON vault_folders (owner_scope, deleted_at);
ALTER TABLE vault_tags ALTER creator_id DROP NOT NULL;
ALTER TABLE vault_tags ADD owner_scope VARCHAR(128) DEFAULT NULL;
CREATE UNIQUE INDEX vault_tags_scope_name_unique ON vault_tags (owner_scope, name);
ALTER TABLE vault_grants ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL;
CREATE INDEX vault_grants_expires_idx ON vault_grants (expires_at);
CREATE INDEX vault_grants_grantee_idx ON vault_grants (grantee_type, grantee_id);

-- SQLite: nullability and FK actions cannot be altered in place; Doctrine rebuilds the tables.

-- Backfill (same migration, after the DDL; Doctrine cannot generate it)
UPDATE vault_items   SET created_by_id = creator_id, secret_changed_at = updated_at;
UPDATE vault_folders SET created_by_id = creator_id;
```

On MySQL / MariaDB mark the migration **non-transactional** (`isTransactional(): false`): DDL auto-commits. No row
changes owner: every existing row stays personal (`owner_scope IS NULL`, `creator_id` set).

**Rollback to 1.15.x** is possible only while no scope row and no temporary grant exists. Check first (read-only, exit
code 1 when a rollback would lose data):

```bash
php bin/console nowo:vault:schema:check-downgrade
# {"safe":true,"scope_items":0,"scope_folders":0,"scope_tags":0,"temporary_grants":0}
```

then drop the new indexes and columns and restore `NOT NULL` on the three `creator_id` columns.

### BC notes

- **`VaultItem::getCreator()`, `VaultFolder::getCreator()`, `VaultTag::getCreator()` are deprecated.** They still
  return the owner of personal rows and throw `VaultOwnerException` for scope rows (which exist only once you enable
  `scopes`). Use `getOwner()` (`VaultOwner`: `isScope()`, `getUser()`, `getScope()`, `key()`, `isUser($user)`) or
  `getCreatedBy()` (audit creator, nullable). Listeners that call `getCreator()` on any item must switch before
  enabling scopes.
- **`VaultGrantService::grant()` and `revoke()` are deprecated** (they still work, create permanent grants only and
  never re-check the actor). Use `grantAs($actor, …, ?$expiresAt)` (returns `VaultGrantResult`: `Created`, `Updated`,
  `Unchanged`; throws `VaultGrantRefusedException` with a reason code) and `revokeAs($actor, $grant)` (false when the
  grant was already gone). Both old methods now also emit `GRANT_CREATED` / `GRANT_UPDATED` / `GRANT_REVOKED`. The
  built-in share pages use the new methods.
- **Grant reads skip expired grants.** `VaultGrantRepositoryInterface` is unchanged; the Doctrine repository's
  `findByGrantee()`, `findGrantedResourceIds()` and `countByResources()` now return active grants only, and every
  access decision re-checks `VaultGrant::isActiveAt()` against the clock (custom repositories keep working). No grant
  expires unless one is created with `expiresAt`. `findByResource()` / `findOne()` / `findById()` still return expired,
  not yet purged rows (the share page shows them as expired).
- **The access guard no longer runs its redundant third grant lookup** with the Doctrine repository: one query loads
  the user's and teams' grants on the item and its folder. A custom `VaultGrantRepositoryInterface` keeps the 1.15
  lookups.
- **Purging an item or folder deletes its grants** in the same transaction (no orphan grants), with
  `GRANT_REVOKED` (reason `resource_purged`).
- **Key rotation (`nowo:vault:reencrypt`) no longer touches `updated_at`** (it used to reorder every list).
- **New constructor arguments are appended and optional** (`VaultItem`, `VaultFolder`, `VaultTag`, `VaultGrant`,
  `VaultAccessGuard`, `VaultGrantService`, `VaultItemCreator`, `VaultItemUpdater`, `VaultTrashService`,
  `VaultSharedItemResolver`, `VaultFolderService`, `VaultRouteLoader`, `VaultManageController`).
- **New route keys** (`scope_items`, `scope_item_new`, `scope_folder_create`, `catalogue`, `health`, `item_attachment`,
  `personal_export`, `personal_export_download`) have
  defaults, so custom `nowo_vault.routes` maps keep working. The scope keys are registered only with `scopes.enabled`
  (and `catalogue` / `health` also with `catalogue.enabled` / `health.enabled`); `item_attachment` with the manage UI;
  the export keys with `export.enabled`.
- **Item form option `password_policy`** (`?VaultPasswordPolicy`, default null): the bundle controllers pass the
  owner's policy for login items; it validates only with `enforceOnSave`. Forms built by hosts are unchanged.
- **The reveal JSON gains keys:** `item_reveal` now answers `{"item", "field", "purpose", "encoding": "plain",
  "value"}` (1.15.2: `{"field", "value"}`). Clients must read `value` only when `encoding` is `plain` (a later
  zero-knowledge mode answers `ciphertext`). The bundled `vault.js` does. A `websites` reveal returns a list.
- **`VaultItemRevealedEvent`** has three more properties (`purpose`, `ownerKey`, `occurredAt`); the constructor
  arguments are appended and optional. Copies dispatch `ITEM_COPIED` (not `ITEM_REVEALED`): add it to your audit
  listener. Since an audit listener that throws now refuses the reveal (503), let it throw only when the audit write
  really failed.
- **Two reveal budgets:** `security.secret_access_rate_limit` (1.15.2, shared with `/logins`) still applies, and the
  web reveal, copy and download routes also count against `reveal.rate_limit`. Use a shared, persistent cache pool
  (`reveal.rate_limit.cache_pool`).
- **Templates:** `item_form.html.twig` gains Copy buttons and an attachment download button (data attributes on the
  hidden `[data-vault-reveal]` element: `data-vault-reveal-copy`, `-copied`, `-auto-hide`, `data-vault-attachment-url`).
  Overrides keep working; copy them to offer the new buttons. New templates `scope_items`, `catalogue`, `health` and
  `export`.
- **Headers:** every bundle response also carries `Expires: 0`.

### Configuration examples

The contract draft's Beacon example used `grants.require_grantee_list` and `reveal.mode`; neither exists (the config
tree refuses them and the container fails to compile). Remove them: the fail-closed grantee list is the 1.15.2 default
(`security.allow_unlisted_grantees: false`, keep it), and on-demand reveal is unconditional since 1.15.2.

```yaml
nowo_vault:
    scopes: { enabled: true, access_resolver: App\Vault\Scope\ScopeAccessResolver }
    security: { deny_status: 404, allow_unlisted_grantees: false }
    grants: { temporary: { max_ttl: 'P30D', max_permission: read } }
```

### New options (all off by default)

```yaml
nowo_vault:
    scopes:
        enabled: false
        access_resolver: null            # service implementing VaultScopeAccessResolverInterface (required when enabled)
    grants:
        temporary:
            min_ttl: PT5M
            max_ttl: P30D
            max_permission: read         # read | write (admin is refused)
            ui: false                    # expiry choice in the share form
    catalogue:
        enabled: false                   # needs scopes.enabled
        per_page: 50
    reveal:
        auto_hide_seconds: 30
        revealable_fields: {}            # per type, e.g. { login: [password, note, username] }; default the secret fields
        rate_limit:
            enabled: true
            per_user_per_minute: 30
            per_user_per_hour: 300
            per_user_item_field_per_minute: 10
            cache_pool: cache.app
    health:
        enabled: false                   # health route; needs scopes.enabled (the services work without it)
        max_items: 2000
        cache_ttl: 0                     # seconds; 0 = never cached
        min_interval_seconds: 10         # one computed report per viewer and owner per window
        breach_cache_ttl: 86400          # breach answers per item and secret change
        cache_pool: cache.app
        breach_checker: null             # service implementing VaultBreachCheckerInterface
        policy_resolver: null            # service implementing VaultPasswordPolicyResolverInterface
        policy: { min_length: 12, min_classes: 3, max_age_days: 365, enforce_on_save: false }
    export:
        enabled: false                   # decrypted personal export routes; needs reauth_checker
        reauth_checker: null             # service implementing VaultReauthenticationCheckerInterface (no default)
        reauth_max_age: 300              # seconds, at most 900
        accepted_primary_factors: [password]
        accepted_mfa_factors: [totp, webauthn]
        formats: [json, csv]
        max_items: 5000
        max_bytes: 52428800
        include_attachments: true        # JSON only
        cooldown_seconds: 600
        cache_pool: cache.app            # cooldown + used proof ids: use a pool shared by all workers
        lock_factory: lock.factory       # symfony/lock factory (if present): atomic proof use + cooldown per user
        csv_raw_values: false            # true = exact CSV cells (no ' before = + - @)
```

GDPR: call `VaultPersonalDataExporter::export($userId)` for access requests and `VaultPersonalDataEraser::erase($userId)`
**before** deleting a user row (it no longer relies on the FK cascades), and remove any interim eraser of your own.

Schedule `php bin/console nowo:vault:grants:purge-expired` (for example every 5 minutes) if you create temporary
grants: access already ends at `expiresAt`, the command deletes the rows and emits `GRANT_EXPIRED`.

## To 1.15.2

Security patch. From **1.15.1**:

```bash
composer update nowo-tech/vault-bundle
php bin/console assets:install
php bin/console doctrine:migrations:diff   # one FK change, see "Schema" below
php bin/console doctrine:migrations:migrate
```

**Secret fields are no longer rendered.** View and edit pages show passwords, CVV, card PIN, card number, document number, secure note content and notes as empty fields with a "stored value hidden" placeholder and a Show button, which calls the new `item_reveal` route (`POST /tools/vault/items/{id}/reveal/{field}`). Saving with a secret field left empty keeps the stored value; tick it under **Clear stored values** to empty it. If you override `item_form.html.twig`, keep the hidden `[data-vault-reveal]` element (or render your own with the reveal URL, the `vault_reveal_<item id>` CSRF token and the labels) and load `vault.js`; if you customised `nowo_vault.routes`, the new `item_reveal` key has the default path `/tools/vault/items/{id}/reveal/{field}`. Run `assets:install` for the new `vault.js`.

**Sharing fails closed.** Without a `GRANT_LIST_QUERY` listener that lists grantees, the share form can no longer grant anyone (before, any user or team id typed in the form was accepted, across tenants). Add a listener that lists the users and teams each sharer may grant (see [examples/AccessControl.md](examples/AccessControl.md)). Only if you knowingly rely on the old free-form behaviour, restore it (unsafe):

```yaml
nowo_vault:
    security:
        allow_unlisted_grantees: true   # UNSAFE: any typed user / team id can be granted access
```

**New options (defaults keep 1.15.1 behaviour apart from the above):**

```yaml
nowo_vault:
    security:
        deny_status: 403                 # or 404: inaccessible items, folders, grants and fields answer "not found"
        secret_access_rate_limit:        # ONE budget per user shared by the reveal route and extension /logins (1 unit per call)
            enabled: true
            max_requests: 60
            window_seconds: 60
            cache_pool: cache.app            # must be shared by all workers and persistent (not an array pool)
```

**Cache headers.** Every bundle response now has `Cache-Control: no-store, private`, `Pragma: no-cache` and `X-Content-Type-Options: nosniff`. Browsers no longer keep vault pages in the back/forward cache.

**Audit.** Listen to `VaultEvents::ITEM_REVEALED` (`VaultItemRevealedEvent`) to record who revealed which field (channel `web`) and which credentials the browser extension received (channel `extension`). The event never carries a value.

**Schema.** `vault_grants.created_by_id` becomes nullable with `ON DELETE SET NULL` (was `NOT NULL`, `ON DELETE CASCADE`): deleting a user no longer deletes the grants that user created. `VaultGrant::getCreatedBy()` may now return `null`; handle it if your code reads it. Reference DDL (default `table_prefix`, `<fk_name>` = the name Doctrine generated, `<user_table>` / `<USER_ID_TYPE>` = your user entity's table and id type):

```sql
-- MySQL / MariaDB
ALTER TABLE vault_grants DROP FOREIGN KEY <fk_name>;
ALTER TABLE vault_grants MODIFY created_by_id <USER_ID_TYPE> DEFAULT NULL;
ALTER TABLE vault_grants ADD CONSTRAINT <fk_name> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL;

-- PostgreSQL
ALTER TABLE vault_grants DROP CONSTRAINT <fk_name>;
ALTER TABLE vault_grants ALTER created_by_id DROP NOT NULL;
ALTER TABLE vault_grants ADD CONSTRAINT <fk_name> FOREIGN KEY (created_by_id) REFERENCES <user_table> (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE;

-- SQLite: Doctrine rebuilds the table.
```

No data changes. Rolling back to 1.15.1 needs no data fix as long as no grant has `created_by_id IS NULL` (delete those rows first).

## To 1.15.1

From **1.15.0**:

```bash
composer update nowo-tech/vault-bundle
```

No action needed when you import the `nowo_vault` route loader (`resource: .`, `type: nowo_vault`, the documented setup). If on Symfony >= 7.3 the vault pages or the browser extension API answered without that import (through the default `controllers: { resource: routing.controllers }` of `config/routes.yaml`), they are gone now: import the loader (see [INSTALLATION](INSTALLATION.md)).

## To 1.15.0

From **1.14.0**:

```bash
composer update nowo-tech/vault-bundle
```

### Template contract (REQ-UI-003)

The manage pages now extend UiKit scaffolds (see [TEMPLATES.md](TEMPLATES.md)). Hosts that only configured the bundle
need nothing; hosts with Twig overrides or a custom layout check the points below.

| Page | Before | Now |
| --- | --- | --- |
| `vault/home.html.twig` | extends `vault/base.html.twig`, block `body` | `page/dashboard`, data block `nowo_ui_dashboard_cards` |
| `vault/items.html.twig`, `shared.html.twig`, `trash.html.twig` | block `body`, local table / pager | `page/list`, data blocks `nowo_ui_list_filters`, `nowo_ui_list_row` |
| `vault/item_form.html.twig`, `share.html.twig` | block `body` | `page/form`, data blocks `nowo_ui_form_body`, `nowo_ui_form_aside` |
| `vault/runtime_config.html.twig` | block `body` | `page/settings`, data block `nowo_ui_settings_section` |

1. **Layout.** Point `nowo_ui_kit.layouts.admin` at your layout (or a bridge that renders the canonical blocks) and
   drop `nowo_vault.templates.layout`. The 1.x default `@NowoVaultBundle/layout.html.twig` (Tabler from a CDN) is
   removed: without configuration the UiKit layout applies. A 1.x host layout that renders `{% block body %}` keeps
   working through a deprecated bridge (deprecation logged).
2. **Removed partials:** `vault/base.html.twig`, `_flashes`, `_page_header`, `_pagination`, `_item_row_actions`,
   `_grants_table`, `_share_form_fields`. Overrides of these files in `templates/bundles/NowoVaultBundle/` are no longer
   used: theme the UiKit partials once (`nowo_ui_kit.theme.partials` / `templates/bundles/NowoUiKitBundle/`) or remap
   slots in `nowo_ui_kit.theme.bundles.NowoVaultBundle`.
3. **Page overrides** (`templates/bundles/NowoVaultBundle/vault/*.html.twig`) that filled `body` must move to the data
   blocks above; prefer `web_ui.templates.<page>` with a template that extends `@!NowoVaultBundle/vault/<page>.html.twig`.
   The pages receive `page` (view model) plus the variables listed in TEMPLATES.md; `layout`, `dashboardRoute` (lists),
   `trashForms`, `restoreForms`, `purgeForms`, `revokeGrantForms`, `tagDeleteForms`, `folderTrashForms` and
   `pagination` were replaced by the view models (`folderActions`, `tagActions`, `grantsTable`).
4. **Assets.** `vault.css` / `vault.js` stack in `nowo_ui_styles` / `nowo_ui_scripts`; a custom layout must render those
   blocks (and load the UiKit scripts for the confirm / generator dialogs: `nowo_ui_partial('scripts')`).
5. **JavaScript globals** `window.VAULT_PASSWORD_URL` / `VAULT_PASSWORD_CSRF_TOKEN` are gone: the generator reads
   `data-vault-password-url` / `data-vault-password-csrf` on its `<dialog>`.
6. **Translations:** `vault.pagination.*` removed (UiKit `pagination.*`); new keys listed in the CHANGELOG.

### Configuration

| Deprecated (removed in 2.0) | Replacement |
| --- | --- |
| `nowo_vault.route_prefix` | `nowo_vault.web_ui.path_prefix` |
| `nowo_vault.templates.layout` | `nowo_vault.web_ui.layout_template` (null = `nowo_ui_kit.layouts.admin`) |
| `nowo_vault.templates.<page>` | `nowo_vault.web_ui.templates.<page>` (`index` → `home`) |
| `nowo_vault.css_framework` | `nowo_ui_kit.css_framework` / `icon_set` |
| Twig global `nowo_vault_css_framework` | `nowo_ui_kit.css_framework` |

The bundle no longer seeds `nowo_ui_kit.css_framework: tabler` / `icon_set: tabler-icons` by default: it only copies the
deprecated `nowo_vault.css_framework` when you set it. Set `nowo_ui_kit.css_framework` (and `icon_set`) explicitly if
you relied on the old default.

### Security (fail closed)

- With `web_ui.enabled: true` (default) the container refuses to compile without CSRF protection
  (`framework.csrf_protection` + `framework.form.csrf_protection`). API-only installs set `web_ui.enabled: false`.
- The container refuses to compile when every `security.*_roles` list is empty without `access_checker` /
  `allow_unauthenticated`.

```bash
composer update nowo-tech/vault-bundle nowo-tech/ui-kit-bundle nowo-tech/form-kit-bundle
php bin/console cache:clear
php bin/console assets:install
```

## To 1.14.0

From **1.13.0**:

```bash
composer update nowo-tech/vault-bundle
```

From **1.13.0**:

```bash
composer update nowo-tech/vault-bundle
```

What changes for hosts:

- **Lists are paginated.** The items, shared and trash pages now show 50 entries per page with previous / next links
  (`?page=N`). To keep the previous single-page behaviour for small vaults, raise the limit (max 1000):

  ```yaml
  nowo_vault:
      pagination:
          per_page: 1000
  ```

  Overridden `items.html.twig` / `shared.html.twig` / `trash.html.twig` keep working but show only the first page
  until you add `{% include '@NowoVaultBundle/vault/_pagination.html.twig' with {pagination: pagination|default(null), routeName: routes.items.name} %}`
  (use `routes.shared.name` / `routes.trash.name` on those pages). Search results are unchanged (capped at 50).
- **Custom item repository** (`VaultItemRepositoryInterface` alias replaced): nothing to do; implement the new
  optional `VaultItemPaginatorInterface` to page in the database instead of in PHP.
- **`VaultItemListQueryEvent` overrides** are returned as given (not sliced): page them in your listener if needed.
- **Browser extension:** `/logins` decrypts at most `browser_extension.max_login_items` (default 1000) login items per
  lookup. Raise it if a single user really has more login items.
- **Logs:** with a logger (MonologBundle) the bundle now writes info / notice / warning / error records for extension
  logins, grant changes, purges, runtime config changes and re-encryption (see `docs/SECURITY.md#logging`; no secrets).
  Silence them with a Monolog channel / level filter if unwanted.
- **Routes:** declared with `#[Route]` attributes but still loaded only through `type: nowo_vault`; keep your
  `config/routes/nowo_vault.yaml` as is and do **not** add an `attribute` import of the bundle controllers.
- **Clock:** token expiry uses `Psr\Clock\ClockInterface` when registered (FrameworkBundle + `symfony/clock`); a
  host `MockClock` in tests now also drives extension token expiry.

## To 1.13.0

From **1.12.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.12.0

From **1.11.1**:

```bash
composer update nowo-tech/vault-bundle
```

- Browser extension: users need `security.access_roles` (or an admin role) to log in to the extension and to use an existing token; tokens of users the firewall user checker refuses are revoked on their next use. Custom `VaultAccessCheckerInterface` implementations receive the token's user as `$user` on these calls and must evaluate it (not the request token, which is empty on Bearer requests).

## To 1.11.1

From **1.11.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.11.0

From **1.10.0**:

```bash
composer update nowo-tech/vault-bundle
```

Security release (SEC-004 re-audit 2026-10-28):

- **Update the browser extension** in every browser (rebuild with `make extension-sync` or reinstall `extension/chrome` /
  `extension/firefox`): old extensions keep working but stay exposed to the autofill click-jacking fix.
- **Shared items:** a grantee with Write keeps editing the content, but can no longer move the item to another folder
  or change its tags. Folder grants now cover only items created by the folder's creator: if you shared a folder that
  holds items other users created, share those items directly.
- On `http://` pages the extension only offers logins whose website is stored as `http://…`.
- `security.allow_unauthenticated: true` fails container compilation in `prod`.
- The extension's vault URL must be `https://` (or `http://localhost`).

## To 1.10.0

From **1.9.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.9.0

From **1.8.2**:

```bash
composer update nowo-tech/vault-bundle
```

### Forms rendered through Form Types (REQ-TWIG-005)

The controllers now always pass form variables for every action that used hand-rolled markup (see [USAGE — Forms in templates](USAGE.md#forms-in-templates-req-twig-005)). **If you override one of these templates**, render the new variables with `form_start` / the child loop / `form_end`:

| Template | New variable(s) |
| -------- | --------------- |
| `vault/items.html.twig` | `searchForm`, `folderForm`, `tagDeleteForms[tag.id]`, `folderTrashForms[folder.id]`, `trashForms[item.id]` |
| `vault/shared.html.twig` / `vault/_item_row_actions.html.twig` | `trashForms[item.id]` (the partial reads it from the including context) |
| `vault/trash.html.twig` | `restoreForms[item.id]`, `purgeForms[item.id]` |
| `vault/share.html.twig` / `vault/_grants_table.html.twig` | `revokeGrantForms[grant.id]` |
| `vault/home.html.twig` / `vault/_password_generator.html.twig` | `generatorForm` |
| `vault/item_form.html.twig` | `form.attachment` (new unmapped child of `VaultItemFormType`; list it among the per-type fields excluded from the trailing loop, as the shipped template does) |
| `vault/runtime_config.html.twig` | `form` (`VaultRuntimeConfigType`) |

Posted names and token ids are unchanged, so existing overrides keep working until migrated. `VaultItemFormType` now contains a `FileType` child, so the item form is rendered as `multipart/form-data`; the identity-card attachment is therefore actually uploaded with the form.

## To 1.8.2

From **1.8.1**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.8.1

From **1.8.0**:

```bash
composer update nowo-tech/vault-bundle
```

## To 1.8.0

From **1.7.0** — security - firewall user checker on browser-extension login by default; explicit service options no longer overridden.

```bash
composer update nowo-tech/vault-bundle
```

- **Extension login user checker:** with `browser_extension.user_checker` unset, the default authenticator now runs `security.user_checker.<firewall>` (firewall `main`, else the first secured firewall) instead of the global `UserCheckerInterface` alias. Users your firewall checker rejects (disabled, locked, unverified…) can no longer obtain extension tokens. To use another firewall set `browser_extension.firewall: <name>` (must have security enabled, otherwise the container fails to compile); an explicit `browser_extension.user_checker` keeps priority.
- `browser_extension.user_provider` / `user_checker` are now actually applied (they were overridden by autowiring); verify the configured service ids exist. CORS wildcard `*` now works in `dev`/`test` as documented (still rejected in `prod`).

## To 1.7.0

From **1.6.0** — resumable re-encryption, per-IP extension login limit, docs, vitest 4.

```bash
composer update nowo-tech/vault-bundle
```

- **Extension login per-IP limit:** failed extension logins are now also counted per client IP across all usernames; after `browser_extension.login_rate_limit.max_attempts_per_ip` failures (default `20`) in `interval_seconds`, that IP gets HTTP 429 for every username. If many users log in through one shared IP (corporate NAT, reverse proxy without trusted proxies configured), raise the value or set `0` to disable. Make sure `framework.trusted_proxies` is set so the real client IP is used.

## To 1.6.0

From **1.5.1** — security - master key protection, user checker on extension login, CORS allow-list honoured.

```bash
composer update nowo-tech/vault-bundle
```

- With a YAML/env `encryption_key`, the runtime config screen no longer stores a database key; rotate with `nowo:vault:reencrypt --persist-new-key`.
- Storing the master key in the database now requires `nowo-tech/doctrine-encrypt-bundle` (it was stored in plaintext before).
- Disabled/locked users can no longer obtain browser-extension tokens. Apps with a custom firewall user checker should set `browser_extension.user_checker: security.user_checker.<firewall>`.
- A non-empty `cors_allowed_origins` must now list the extension origin explicitly (`chrome-extension://<id>`).

## To 1.5.1

From **1.5.0** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/vault-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.5.0

From **1.4.8** — Doctrine ORM `SortDirection`; `doctrine/orm` `^3.7` required.

```bash
composer update nowo-tech/vault-bundle doctrine/orm --with-all-dependencies
php bin/console cache:clear
```

- **Requirement:** `doctrine/orm` **^3.7** (ORM 2.x and 3.0–3.6 are no longer supported). Upgrade Doctrine ORM first if your application is on an older version.
- No configuration or public API changes.

## To 1.4.8

From **1.4.7** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/vault-bundle
php bin/console cache:clear
```

No breaking changes. Behaviour notes (FrankenPHP worker hardening):

- `ITEM_READ_ONLY_RESOLVE` listeners are now called on every access check instead of once per (user, item) for the worker life. Keep them cheap, or cache inside the listener with a per-request scope.
- The `DoctrineOrm*Repository` constructors accept two new optional arguments (`?ManagerRegistry $registry`, `?string $managerName`); the bundle wires them automatically. Subclassing is not possible (classes are `final`), so no action is needed.
- With `config_storage.enabled: true`, runtime config is read from `config_storage.cache_pool` on every call. Use a pool shared by all workers (for example `cache.app` on Redis or a shared filesystem); a per-process pool such as `cache.adapter.array` would still diverge between workers.
- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## From 1.4.6 to 1.4.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/vault-bundle
```

## From 1.4.6 to 1.4.7

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/vault-bundle
```

# Upgrading

This document describes how to upgrade between versions of Vault Bundle.

## 1.3.0 (2026-08-03)

Minor release: REQ-UI-002 — `security.allow_unauthenticated`, AllowAll checker, SecurityBundle compile-time guard, and soft manage-UI gate (no hard `IsGranted` attribute).

```bash
composer require nowo-tech/vault-bundle:^1.3
php bin/console cache:clear
```

### Behaviour

| Topic | Before | 1.3.0 |
| --- | --- | --- |
| Apps without SecurityBundle | Could boot | Boot fails with `LogicException` unless `allow_unauthenticated: true` |
| Manage auth attribute | `#[IsGranted('IS_AUTHENTICATED')]` | Feature/admin checks via `VaultAccessCheckerInterface` (+ optional AllowAll) |
| Host firewall | Recommended | Still required in production (`access_control` on `/tools/vault`) |

**Trusted local demos only:**

```yaml
nowo_vault:
    security:
        allow_unauthenticated: true   # never in production
```

**Production:** keep `allow_unauthenticated: false`, install SecurityBundle, grant `access_roles` / area roles, and protect manage paths with host `access_control`.

### Breaking changes

Applications that used the manage UI **without** SecurityBundle must install/configure it or set `allow_unauthenticated: true` for non-production use. Item/folder ACL still requires an authenticated user for mutating flows.

## 1.2.0 (2026-07-30)

Optional host CSS stack hint and manage page shell for REQ-UI-001. **Non-breaking** — default remains `tabler` (demo CDN behaviour for Tabler/Bootstrap-compatible values).

```bash
composer require nowo-tech/vault-bundle:^1.2.0
php bin/console cache:clear
```

### Behaviour

- New root key `css_framework` → Twig global `nowo_vault_css_framework`.
- Manage pages go through `@NowoVaultBundle/vault/base.html.twig` (stacks package CSS/JS with `parent()`). Prefer `templates.layout` at the host chrome instead of freezing every page.
- If you **overrode** manage templates that `{% extends layout %}`, update them to `{% extends '@NowoVaultBundle/vault/base.html.twig' %}` (or keep extending your own shell and stack assets yourself).

```yaml
nowo_vault:
    css_framework: bootstrap5   # or tabler | custom | …
    templates:
        layout: 'layouts/app.html.twig'
```

## 1.1.5 (2026-07-29)

Maintainer tooling, docs, CI matrix, and PHPStan/coverage hygiene. **No action required** for applications already on 1.1.4 — no bundle API, schema, or configuration changes.

```bash
composer update nowo-tech/vault-bundle
```

Demo contributors: Symfony 8 demo image is `dunglas/frankenphp:1-php8.5-bookworm`. Recreate the container after pull if you use the demo.

## 1.1.4 (2026-07-22)

Demo and maintainer tooling patch. **No action required** for applications already on 1.1.3 — no bundle API, schema, or configuration changes.

Demo contributors: FrankenPHP mode is no longer tied to `APP_ENV`. Set `FRANKENPHP_MODE=classic` or `worker` in `demo/symfony8/.env`, then recreate the container (`docker compose up -d` / `make up`). See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).

## 1.1.3 (2026-07-16)

Contributor and maintainer tooling release. **No action required** for applications already on 1.1.2 — no bundle API, schema, or configuration changes.

Maintainers and contributors: after cloning, run `make setup-hooks` once so commit messages reject Cursor `Co-authored-by` trailers (REQ-GIT-001). See [CONTRIBUTING.md](CONTRIBUTING.md) and [GITHUB_CI.md](GITHUB_CI.md).

## 1.1.2 (2026-07-13)

CI and test-infrastructure patch. **No action required** for applications already on 1.1.1 — no bundle API, schema, or configuration changes.

If you run **Symfony 8** with **PHP 8.4+**, ensure `doctrine/doctrine-bundle` ^3.0 is installed (see [1.0.0](#symfony-8--doctrine) below). Doctrine Bundle 3 enables native lazy objects by default on supported PHP versions.

## 1.1.1 (2026-07-08)

Documentation and demo developer-experience release. **No action required** for applications already on 1.1.0 — no bundle API, schema, or configuration changes.

Maintainers: see [SPEC-KIT.md](SPEC-KIT.md) and [specs/001-baseline/](../specs/001-baseline/) when updating production code under `src/`.

Demo contributors: sibling path repos for `doctrine-encrypt-bundle` and `tag-input-bundle` are no longer mounted in Docker; the demo resolves them from Packagist.

## 1.1.0 (2026-07-05)

### Summary

Adds browser extension API, item tags, optional database-backed runtime config, encryption key rotation command, CSRF on manage POST actions, and extension login rate limiting.

### Upgrade steps

```bash
composer update nowo-tech/vault-bundle
php bin/console doctrine:migrations:migrate   # or doctrine:schema:update --force
php bin/console assets:install
php bin/console cache:clear
```

New tables (when using default `table_prefix: vault_`):

| Table | When |
|-------|------|
| `vault_tags`, `vault_item_tag` | Always (tags feature) |
| `vault_settings` | When `config_storage.enabled: true` |
| `vault_extension_tokens` | When `browser_extension.enabled: true` |

### CSRF on manage UI (breaking for custom integrations)

All state-changing POST actions in the manage UI now require a valid CSRF token (`_token` form field, `X-CSRF-Token` header, or JSON `_token`). Custom forms or JavaScript that POST to vault manage routes must include the token from the rendered page or Symfony CSRF service.

The browser extension API uses Bearer tokens and is **not** affected by manage CSRF.

### Browser extension (optional)

To enable the extension API:

```yaml
nowo_vault:
    browser_extension:
        enabled: true
        user_provider: security.user.provider.concrete.app_user_provider
```

Add firewall rule for `^/api/vault/extension` with `PUBLIC_ACCESS` (Bearer auth is enforced by the bundle). Schedule token cleanup:

```bash
php bin/console nowo:vault:extension-tokens:purge
```

See [BROWSER-EXTENSION.md](BROWSER-EXTENSION.md).

### Runtime config (optional)

```yaml
nowo_vault:
    config_storage:
        enabled: true
```

Creates `{table_prefix}_settings` for runtime overrides (e.g. encryption key stored in DB). See [CONFIGURATION.md](CONFIGURATION.md#database-backed-runtime-configuration).

### Encryption key rotation

Use `nowo:vault:reencrypt` — see [ENCRYPTION-KEY-ROTATION.md](ENCRYPTION-KEY-ROTATION.md). Demo walkthrough: `make -C demo/symfony8 vault-rotation-demo`.

### Template overrides

If you copied `@NowoVaultBundle/vault/_item_access.html.twig`, switch to `_item_row_actions.html.twig` (the former was removed).

### Suggested packages

- `nowo-tech/tag-input-bundle` — tag input in item forms
- `nowo-tech/doctrine-encrypt-bundle` — encrypt `vault_settings.encryption_key` at rest

## 1.0.0 (2026-07-04)

Initial release of **VaultBundle** (password and secrets vault). If you previously used **YopassBundle** from the same repository lineage, treat this as a new package:

- Composer package: `nowo-tech/vault-bundle`
- Config alias: `nowo_vault`
- Routes type: `nowo_vault`

There is no automatic migration from Yopass share links. Plan a fresh schema and data import if needed.

### Upgrade steps

```bash
composer require nowo-tech/vault-bundle
php bin/console doctrine:migrations:migrate
php bin/console assets:install
php bin/console cache:clear
```

Configure `encryption_key`, `user_class`, and security roles as described in [INSTALLATION.md](INSTALLATION.md) and [CONFIGURATION.md](CONFIGURATION.md).

### Symfony 8 + Doctrine

If you run Symfony 8, ensure **`doctrine/doctrine-bundle` ^3.0** is installed:

```bash
composer require doctrine/doctrine-bundle:^3.0
```

### Access control events

Optional listeners for listing, grants, teams, and read-only items:

| Event | When |
|-------|------|
| `VaultItemListQueryEvent` | Before the vault list query |
| `VaultItemListResultEvent` | After items are loaded |
| `VaultItemAccessCheckEvent` | Before item view/edit/trash/share |
| `VaultFolderAccessCheckEvent` | Before folder actions |
| `VaultGrantListQueryEvent` | When building share UI for items/folders |
| `VaultItemReadOnlyEvent` | Resolve view-only access per item |

See [examples/AccessControl.md](examples/AccessControl.md).

[1.1.4]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.4
[1.1.3]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
### FormKitBundle (admin forms)

If you use admin/dashboard Symfony forms, ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `vault` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

## Unreleased

## To 1.4.6

From **1.4.5** — Review Flex `when@prod` security defaults and extension `access_control` for `/api/vault/extension`.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.5

No application upgrade steps. Transitive dependency refresh only.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.4

Review Flex recipe `security_nowo_vault.yaml` after update.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.3

Review Flex recipe `security_nowo_vault.yaml`: `^/tools/vault` requires `ROLE_USER`. Adjust roles or paths for your app.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.2

No application upgrade steps.

```bash
composer update nowo-tech/vault-bundle
```

## To 1.4.1

No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

```bash
composer update nowo-tech/vault-bundle
php bin/console cache:clear
```

## To 1.4.0

From **1.3.0** — Adds FormKit and/or UiKit where applicable, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register TwigExtraBundle, NowoFormKitBundle, and NowoUiKitBundle if Flex did not. See CHANGELOG.

```bash
composer update nowo-tech/vault-bundle
php bin/console cache:clear
```

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

