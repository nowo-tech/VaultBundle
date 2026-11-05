# Usage

## Table of contents

- [Manage UI](#manage-ui)
- [Item types](#item-types)
- [Tags](#tags)
- [Folders](#folders)
- [Sharing](#sharing)
- [Scope vaults](#scope-vaults)
- [Temporary grants](#temporary-grants)
- [Catalogue](#catalogue)
- [Reveal, copy and attachments](#reveal-copy-and-attachments)
- [Password health](#password-health)
- [Personal data](#personal-data)
- [Decrypted export](#decrypted-export)
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

## Scope vaults

Since 1.16.0 a host scope (a project, an organization) can own items, folders and tags (`scopes.enabled`, see
[CONFIGURATION](CONFIGURATION.md#scope-vaults-scopes)). Link your project and organization pages to
`nowo_vault_scope_items` with the scope key (`path('nowo_vault_scope_items', {scope: 'project-' ~ project.id})`).

- **Who sees what:** the host resolver gives each member a baseline (`read`, `write`, `admin`, or none) plus the
  `catalogue`, `health` and `create` flags. A member without a baseline sees only the items explicitly granted to them;
  a non-member sees nothing, even with a direct grant.
- **Create:** members with `create` add items (`scope_item_new`) and folders (`scope_folder_create`). The scope comes
  from the route, never from the form; a folder or tag of another owner is refused.
- **Organise:** folder, tags and *Hide from the catalogue* need admin on the item (the scope baseline or a grant);
  `write` members edit the content only.
- **Existing item routes** (view, edit, share, reveal, trash, restore, purge) serve scope items too.
- **Moving items between owners** (personal ↔ scope, scope ↔ scope) is not offered.
- Hosts that render their own pages call `VaultScopeItemLister::list()`, `VaultItemCreator::createInScope()`,
  `VaultFolderService::createInScope()` and `VaultTagService::syncItemTags()` (scope tags), which apply the same checks.

## Temporary grants

`VaultGrantService::grantAs($actor, $resourceType, $resourceId, $granteeType, $granteeId, $permission, $expiresAt)`
is the entry point for the UI and for host flows such as an access-request approval:

```php
use Nowo\VaultBundle\Enum\{GranteeType, VaultPermission, VaultResourceType};
use Nowo\VaultBundle\Exception\VaultGrantRefusedException;

try {
    $result = $grants->grantAs($approver, VaultResourceType::Item, $itemId, GranteeType::User, $requesterId, VaultPermission::Read, $clock->now()->modify('+1 day'));
    // VaultGrantResult::Created | Updated | Unchanged (already covered by a permanent grant)
} catch (VaultGrantRefusedException $e) {
    // $e->getReason(): actor_cannot_share, self_grant, grantee_not_allowed, grantee_not_in_scope,
    // expiry_out_of_range, temporary_permission_too_high, would_replace_permanent, policy_denied (or your code)
}
```

It re-checks that the actor may Share the resource **now**, so a stale approval cannot grant anything. A temporary
grant never replaces or upgrades a permanent one. Access ends at `expiresAt` on every read; schedule
`nowo:vault:grants:purge-expired` for the `GRANT_EXPIRED` event. `revokeAs($actor, $grant)` re-checks Share and returns
false when the grant was already gone. With `grants.temporary.ui: true` the share form offers an expiry, and the share
page shows *until …* and an *Expired* badge until the purge.

## Catalogue

With `scopes.enabled` and `catalogue.enabled`, members with the `catalogue` flag browse
`/tools/vault/scopes/{scope}/catalogue`: title, type, scope tags and the date of the last change of every item they
could ask access to, and a *You have access* badge. Nothing from the payload (username, URL, password, note,
attachment) is shown or loaded. Offer *Request access* by overriding the row block:

```twig
{# templates/bundles/NowoVaultBundle/vault/catalogue.html.twig #}
{% extends '@!NowoVaultBundle/vault/catalogue.html.twig' %}

{% block vault_catalogue_row_actions %}
    {% if not entry.viewerHasAccess %}
        <a href="{{ path('app_vault_access_request', {item: entry.id}) }}">{{ 'Request access'|trans }}</a>
    {% endif %}
{% endblock %}
```

The block receives `entry` (`VaultCatalogueEntry`) only. Your approval flow then calls `VaultGrantService::grantAs()`
(see [Temporary grants](#temporary-grants)). Hosts that render their own catalogue call `VaultCatalogue::page()`.
Scope admins can keep an item out of the catalogue with *Hide from the catalogue* (access does not change).

## Reveal, copy and attachments

Secret fields are never in the page (1.15.2). The item page offers, per stored secret field, **Show** (the value
stays `reveal.auto_hide_seconds` in the input, then disappears) and **Copy** (written to the clipboard, where it stays until something else is copied; never into the
page), and for documents with a stored file a **Download attachment** button. All three are POST requests with the
`vault_reveal_<item id>` CSRF token in `X-CSRF-Token`, checked for View access, vetoable per field
(`ITEM_FIELD_REVEAL_CHECK`), rate limited, and audited **before** the value leaves (`ITEM_REVEALED`, `ITEM_COPIED`,
`ITEM_ATTACHMENT_DOWNLOADED`; an audit listener that throws refuses the request with 503):

```bash
curl -X POST -H "X-CSRF-Token: $TOKEN" -d purpose=copy https://app.example/tools/vault/items/$ID/reveal/password
# {"item":"<id>","field":"password","purpose":"copy","encoding":"plain","value":"…"}
```

Read `value` only when `encoding` is `plain`.

## Password health

With `scopes.enabled` and `health.enabled`, members whose `VaultScopeAccess::$health` is true (and whom no
`HEALTH_ACCESS_CHECK` listener denies) open `/tools/vault/scopes/{scope}/health`: per login item they may View, *Weak*
(with the missing rules), *Reused (group n)*, *Old (n days)* and *Breached* (only with `health.breach_checker`). The
report is computed now, in memory, and stores nothing; items they cannot View are only counted. Every computed report
decrypts each examined password, so it is limited to one per viewer and owner per `health.min_interval_seconds`. Hosts call the service
directly, for example for the personal vault:

```php
$report = $healthReporter->forPersonalVault($user);   // VaultHealthReporterInterface
foreach ($report->items as $result) {                  // VaultHealthItemResult: flags, never a value
    // $result->itemId, $result->weak, $result->weakReasons, $result->reuseGroup, $result->old, $result->breached
}
```

A host policy per owner (organization settings, for example) implements `VaultPasswordPolicyResolverInterface`:

```php
final class OrganizationPolicyResolver implements VaultPasswordPolicyResolverInterface
{
    public function forOwner(VaultOwner $owner): ?VaultPasswordPolicy
    {
        return $owner->isScope() ? new VaultPasswordPolicy(minLength: 16, minClasses: 3, maxAgeDays: 180, enforceOnSave: true) : null;
    }
}
```

`null` falls back to `health.policy`. With `enforceOnSave` the item form refuses a typed login password that breaks the
policy; the message lists the missing rules only.

## Personal data

For access and erasure requests (GDPR):

```php
$data   = $exporter->export((string) $user->getId());  // VaultPersonalDataExporter: metadata only, JSON-serialisable
$result = $eraser->erase((string) $user->getId());     // VaultPersonalDataEraser, before deleting the user row
// $result->toArray(): grants_received, grants_on_personal, grants_created_anonymised, item_tags, items, folders, tags,
// scope_items_anonymised, scope_folders_anonymised, extension_tokens
```

Grants the user gave on scope resources are kept (their grantees keep access) with `created_by_id = NULL`; scope items
and folders the user created stay with the scope. Listen to `PERSONAL_DATA_ERASED` (counts) and `GRANT_REVOKED`
(reason `erasure`) for your audit trail.

## Decrypted export

With `export.enabled`, a user downloads a decrypted copy of their **personal** vault (never items shared with them,
never scope items) from `/tools/vault/export`, after a fresh re-authentication that your application performs. Implement
the hook:

```php
final class ReauthChecker implements VaultReauthenticationCheckerInterface
{
    public function getProof(UserInterface $user, Request $request, string $purpose): ?VaultReauthProof
    {
        $step = $this->reauthStore->latest($user, $request->getSession(), $purpose); // your re-auth step, not the login
        if ($step === null || $step->used) {
            return null;
        }

        return new VaultReauthProof(
            $step->id,
            (string) $user->getId(),
            $purpose,                       // 'nowo_vault.personal_export'
            $step->verifiedAt,              // when the factors below were verified in that step
            $step->factors,                 // e.g. ['password', 'totp']
            $this->mfa->isEnrolled($user),  // read from the store, not from the session
            hash('sha256', $request->getSession()->getId()),
        );
    }

    public function consume(VaultReauthProof $proof): void
    {
        $this->reauthStore->markUsed($proof->proofId);
    }

    public function getReauthenticationUrl(Request $request, string $purpose, string $returnTo): string
    {
        return $this->urls->generate('app_reauth', ['purpose' => $purpose, 'return' => $returnTo]);
    }
}
```

The bundle checks the proof itself (user, purpose, age, factors, MFA, session binding, single use), then the limits and
the cooldown, consumes the proof, dispatches `ITEMS_EXPORTED` (write your audit row there; throw to refuse) and streams
the file. A refusal answers 403 / 413 / 429 / 503 without saying which check failed; the log has the reason code.

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
| `ITEM_REVEALED` | A secret field was revealed (web, purpose `reveal`) or a credential returned to the browser extension (purpose `autofill`) |
| `ITEM_COPIED`, `ITEM_ATTACHMENT_DOWNLOADED` | A field was copied / an attachment downloaded (1.16.0; same `VaultItemRevealedEvent`) |
| `ITEM_FIELD_REVEAL_CHECK` | Veto one field or the attachment before it leaves (1.16.0) |
| `REVEAL_THROTTLED` | A reveal hit `reveal.rate_limit` (1.16.0; alert on scraping) |
| `CATALOGUE_ACCESS_CHECK` | Grant or deny a scope catalogue (1.16.0) |
| `HEALTH_ACCESS_CHECK` | Grant or deny a scope health report (1.16.0) |
| `HEALTH_REPORT_GENERATED` | A health report was produced (1.16.0; actor, owner key, counts only) |
| `ITEMS_EXPORTED` | A decrypted personal export is about to stream (1.16.0; actor, count, format, factors; throw = refuse) |
| `PERSONAL_DATA_ERASED` | A user's vault data was erased (1.16.0; user id, counts) |
| `GRANT_POLICY_CHECK` | Veto a grant in `grantAs()` with a reason code (1.16.0) |
| `GRANT_CREATED`, `GRANT_UPDATED`, `GRANT_REVOKED`, `GRANT_EXPIRED` | Grant audit trail (`VaultGrantChangeEvent`: ids, permission, expiry, actor, reason; after the flush) |
| `ITEM_CHANGED` | Item activity (`VaultItemChangeEvent`: created, updated, trashed, restored, purged, moved; `secretChanged`) |

## Lists and pagination

The items, shared and trash pages are paginated (`?page=N`, `nowo_vault.pagination.per_page`, default 50; see [CONFIGURATION.md](CONFIGURATION.md#pagination)). Own / folder / tag / trash lists page in the database (Doctrine `Paginator`, tags fetch-joined, so the page costs a constant number of queries); shared items are loaded through the grant ids and sliced in PHP; search results stay capped at 50 and are not paginated. A custom `VaultItemRepositoryInterface` keeps working: implement `VaultItemPaginatorInterface` to page in the database, otherwise the lister slices the full list. Items returned by a `VaultEvents::ITEM_LIST_QUERY` override are used as given (your listener owns paging).

The browser extension `/logins` lookup decrypts at most `browser_extension.max_login_items` login items (default 1000, own items first, most recently updated first) and logs a warning when the limit truncates the scan.

## Twig overrides

The bundle registers **`@NowoVaultBundle/`**. Application files under **`templates/bundles/NowoVaultBundle/`** win over vendor (`TwigPathsPass`).

**Freeze rule:** an application file at the override path always wins and will **not** pick up vendor changes for that path until you remove or merge it. Prefer the UiKit theme (`nowo_ui_kit.layouts` / `css_framework` / `theme`, once for every bundle) and the per-page `web_ui.templates.*` keys ([CONFIGURATION.md](CONFIGURATION.md#manage-web-ui-web_ui)) over copying manage pages.

Registration: `TwigPathsPass` prepends `templates/bundles/NowoVaultBundle/` (when it exists) and appends the bundle's `src/Resources/views` to the `NowoVaultBundle` namespace, so overrides are searched first. It is always active (the manage UI is not optional).

**Upgrade-safe customisation (preferred → last resort):**

| Depth | How | On a bundle update |
|-------|-----|--------------------|
| No Twig fork | `nowo_ui_kit.layouts.admin` (host shell / bridge), `nowo_ui_kit.css_framework` / `theme` (slots, tokens, `theme.bundles.NowoVaultBundle`), CSS on `nowo-ui-*` / `vault-*` hooks | Every vendor template fix applies automatically |
| Re-skin a view type | Override a UiKit scaffold once in `templates/bundles/NowoUiKitBundle/page/<type>.html.twig` | Applies to every bundle; only that scaffold is frozen |
| Surgical override | Point one `web_ui.templates.<page>` key at your own file that `{% extends '@!NowoVaultBundle/vault/<page>.html.twig' %}` and overrides one data block with `{{ parent() }}` | Only that file is frozen |
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
| `vault/home.html.twig` | Landing page — `page/dashboard` (sections, item types, password generator, runtime config action) |
| `vault/items.html.twig` | Items list — `page/list` (folders / tags panels, search, cells) |
| `vault/shared.html.twig` | Items shared with the user — `page/list` |
| `vault/trash.html.twig` | Trashed items — `page/list` (restore / purge row actions) |
| `vault/item_form.html.twig` | Create / edit / view form of one item — `page/form` (per-item-type layout) |
| `vault/share.html.twig` | Share form + current grants of an item or folder — `page/form` (+ aside table) |
| `vault/runtime_config.html.twig` | Database-backed runtime configuration — `page/settings` |
| `vault/_password_generator.html.twig` | Password generator body (UiKit `dialog` partial) |
| `vault/_legacy_layout.html.twig` | Deprecated bridge around a 1.x layout (removed in 2.0) |
| `Form/vault_item_form_theme.html.twig` | Tag input container of the item form (with `nowo-tech/tag-input-bundle`) |

Data blocks, view-model fields and the page → scaffold map: [TEMPLATES.md](TEMPLATES.md).

Renaming or removing a `<subpath>` or a stable `{% block %}` is treated as a breaking change and is listed in [UPGRADING.md](UPGRADING.md).

Manage pages extend the UiKit scaffolds (REQ-UI-003), which extend `nowo_ui_kit.layouts.admin` (or
`nowo_vault.web_ui.layout_template`). Each page stacks `vault.css` / `vault.js` with `{{ parent() }}` in the canonical
`nowo_ui_styles` / `nowo_ui_scripts` blocks, so the host layout keeps its own assets. The CSS stack, icon set and
row-action display come from `nowo_ui_kit` (one theme per host):

```yaml
nowo_ui_kit:
    css_framework: bootstrap5   # tabler | tailwind | foundation | custom
    layouts:
        admin: 'layouts/admin.html.twig'
```

**Modals and confirms:** destructive actions (move to trash, purge, delete a tag, trash a folder, remove access) are
POST + CSRF forms behind the UiKit confirm `<dialog>`; the password generator is the UiKit `dialog` partial opened by
`data-nowo-ui-dialog-open="vault-password-generator"`. Both need the UiKit scripts the UiKit layout loads
(`nowo_ui_partial('scripts')`); `vault.js` opens the generator itself when they are absent. No inline script or
`on*=` handler is printed (CSP friendly).

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
