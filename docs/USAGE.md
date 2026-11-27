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
- [Header search (SearchKit)](#header-search-searchkit)
- [Reveal, copy and attachments](#reveal-copy-and-attachments)
- [Password health](#password-health)
- [Personal data](#personal-data)
  - [With UserKit (2.5.0)](#with-userkit-250)
- [Decrypted export](#decrypted-export)
- [Read-only access](#read-only-access)
- [Events](#events)
- [Lists and pagination](#lists-and-pagination)
- [Twig overrides](#twig-overrides)
- [Translations](#translations)
- [Standard Symfony host](#standard-symfony-host)
- [DDD / hexagonal host](#ddd--hexagonal-host)
- [Item limits (2.5.0)](#item-limits-250)
- [Browser extension tokens per user (2.5.0)](#browser-extension-tokens-per-user-250)
- [Browser console](#browser-console)

## Manage UI

Authenticated users open the vault at `/tools/vault` (configurable via `nowo_vault.routes`).

- **Vault** — own items, organized by folder
- **Shared items** — items granted via `VaultGrant` (user or team)
- **Trash** — soft-deleted items (restore or purge)
- **Password generator** — sidebar modal + inline on login forms

**Header create actions.** By default the items lists show one *+ type* button per item type. With
`nowo_vault.web_ui.header_actions: page_actions` (UiKit 2.21+) they show a single *New item* entry in the page-actions
menu instead; it opens a UiKit dialog (`vault-new-item-dialog`) with one create link per type (active folder kept),
and the empty items list offers the same entry. A host with the compact header
(`nowo_ui_kit.header.compact_actions: bar`) reads the same `PageHeader::$actions`. The dialog needs JavaScript to open
(UiKit's dialog script, loaded by the UiKit layouts); the per-type URLs (`/tools/vault/items/new/{type}`) are unchanged.

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

1.18.0: `sharing.enabled: false` turns the share UI off (no *Share* action, share / revoke routes answer 404) for hosts
that do not offer sharing yet, e.g. before a `GRANT_LIST_QUERY` listener exists. See
[CONFIGURATION](CONFIGURATION.md#sharing-sharing).

Team membership is resolved via `VaultTeamMembershipProviderInterface` (configure `nowo_vault.team_membership_resolver`).

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

**Creator filter (2.5.0).** `VaultCatalogueQuery::$ownerId` keeps the items one person created (the vault user id: the
string form of the host user's `getId()`, compared exactly). The page has *Everyone* / *Created by me* chips and accepts
`?owner=<id>` from your own links (shown as *Selected person*; the page never shows another person's name or id).
Items of an erased creator match no owner. A blank, unknown, malformed or over-long id (more than 128 characters)
gives an empty page, never an error:

```php
$page = $catalogue->page($viewer, 'org-1', new VaultCatalogueQuery(ownerId: (string) $creator->getId()));
```

## Header search (SearchKit)

Since 1.21.0 the vault can add the group **`vault_items`** to the header search of
[SearchKit](https://github.com/nowo-tech/SearchKitBundle) 1.9 or later (the Ctrl/⌘+K palette and the results page).
SearchKit needs PHP 8.3 or later; the vault keeps supporting PHP 8.2 without it. The group is **off by default**: a host
switches it on explicitly.

```bash
composer require nowo-tech/search-kit-bundle:^1.9
```

```yaml
nowo_vault:
    search_kit:
        enabled: true   # opt-in
```

The extension then tags the definition and SearchKit builds the provider.

**What is searched and shown.** Only the item **name** (the `title` column, matched with an escaped `LIKE`). A row shows
the name, the item type and, when the viewer may see it, the folder name, and links to the item page
(`routes.item_view`). Secret values, notes, URLs, card numbers, attachments, custom fields, tags and history are never
matched, shown or decrypted: they live in the encrypted payload, which the search never reads. The group has no "see
all" link, because no vault page lists the same set (the items page shows the viewer's own items only).

**Which items.** The items the viewer may open on the item page:

- the viewer's own personal items;
- items shared with the viewer or one of its teams by an active item grant, or by an active grant on a folder that
  belongs to the item's owner (a grant on a folder never covers another user's items moved into it; an expired grant
  counts for nothing);
- with `scopes.enabled`, items of the scope vaults that a `VaultEvents::SEARCH_SCOPES` listener names (see below) and in
  which the scope access resolver says the viewer is a member: every item with a baseline, the granted ones without;
- never an item in the trash, and never one an `ITEM_ACCESS_CHECK` listener denies.

Every rule except the host listeners is applied **in SQL**, so a row the viewer may not see never takes a slot of the
candidate window: it cannot change the results, the count or whether the count is exact. The ids are compared by the
database: with `user_mapping: id` the user id columns, and with both mappings the grantee id (`vault_grants.grantee_id`,
which holds user **and team** ids; since 1.21.0 for the relation mapping), use a binary NO PAD collation on MySQL /
MariaDB (PostgreSQL and SQLite compare byte for byte), so `UserA` is never `usera` nor `usera `, and team `Ops` or `Öps`
is never `ops`. With the relation mapping the owner columns are foreign keys to your users table and compare like its
primary key. Then one batched call to
`VaultAccessGuard` checks the window again (one grant query), then SearchKit's shared `nowo_search_kit.item_visibility`
event runs. A refused search answers nothing at all (no count, no hint).

What is left to PHP only: rows an `ITEM_ACCESS_CHECK` or `nowo_search_kit.item_visibility` listener denies, and, on a
host whose own migration dropped the binary collation of those id columns (or a relation-mapping host that has not run
the 1.21.0 migration of `grantee_id`, see UPGRADING), look-alike ids. Such rows are still never
shown or counted, but they can take window slots (`search_kit.max_candidates`).

The viewer is the signed-in user whose identifier is the query's, and only a user the vault accepts (`user_class` or
`accepted_user_classes`, with a usable id); its access checker must allow `canAccess()` and `canList()`.

**The folder name** is shown only for the viewer's own personal folder, or a folder of a scope where the viewer has a
baseline, when the folder belongs to the item's owner and is not in the trash. A `FOLDER_ACCESS_CHECK` listener can still
hide it. A viewer who only holds a grant sees no folder name.

**Sensitive group.** Item names can be private, so the group is `sensitive()`: SearchKit runs it only while its recents
are revalidated on the server or off (`nowo_search_kit.recent.revalidate: true`, or `recent.enabled: false`). Stored
recents are revalidated through the same checks (`routes.item_view` and a lower-case UUID only).

**Scope vaults are searched only when you name them** (`VaultEvents::SEARCH_SCOPES`, `VaultSearchScopesEvent`, once per
search). The scope access resolver answers per scope and cannot list a user's scopes, so the search fails closed: with no
listener, no scope vault is searched. List the viewer's scopes; the resolver is then asked about those scopes only (once
per request), never about the scope of a row:

```php
use Nowo\VaultBundle\Event\VaultEvents;
use Nowo\VaultBundle\Event\VaultSearchScopesEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: VaultEvents::SEARCH_SCOPES)]
final class VaultSearchScopes
{
    public function __construct(private readonly ProjectMembers $members) {}

    public function __invoke(VaultSearchScopesEvent $event): void
    {
        $event->limitTo($this->members->scopeKeysOf((string) $event->getUserId())); // e.g. ['project-42', 'org-7']
        // $event->exclude(['project-archived']);  // never search these
        // $event->excludeAll();                   // no scope vault
    }
}
```

At most 100 scopes are searched per search (`VaultItemSearchDefinition::MAX_SCOPES`). A later `limitTo()` intersects with
an earlier one, `exclude()` removes scopes, and a scope the resolver refuses is never searched, whatever a listener
says. See [CONFIGURATION](CONFIGURATION.md#searchkit-group-search_kit) for the options.

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

1.18.0: Show / Hide and Copy are UiKit icon buttons (eye, eye-off, copy; `aria-label` and tooltip) in a row under the
input. On the edit page the PasswordStrength rules show only while the password input holds a value (typed or
revealed); the view page has no strength meter.

In your own code, `VaultFieldRevealer::reveal()` / `attachment()` refuse users the vault does not accept but do not check
ownership or grants: call `VaultAccessGuard::canAccessItem($user, $item, VaultAccessAction::View)` first, as the
bundle's routes do.

## Password health

With `scopes.enabled` and `health.enabled`, members whose `VaultScopeAccess::$health` is true (and whom no
`HEALTH_ACCESS_CHECK` listener denies) open `/tools/vault/scopes/{scope}/health`: per login item they may View, *Weak*
(with the missing rules), *Reused (group n)*, *Old (n days)* and *Breached* (only with `health.breach_checker`). The
report is computed now, in memory, and stores nothing; items they cannot View are only counted. Every computed report
decrypts each examined password, so it is limited to one per viewer and owner per `health.min_interval_seconds`. Hosts call the service
directly, for example for the personal vault:

```php
$report = $healthReporter->reportForPersonalVault(VaultUserRef::ofId($userId)); // VaultHealthReportProviderInterface
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

Every erasure step has its export section (`VaultPersonalDataExporter::ERASURE_SECTIONS`; 2.5.0 adds
`grants_on_personal` and `scope_folders_created`). Called inside a transaction your code opened on the vault's
connection, the erasure joins it (no transaction or savepoint of its own): your commit or rollback decides for all of
it. Its `GRANT_REVOKED` and `PERSONAL_DATA_ERASED` then wait for your outermost commit and are dropped on rollback,
whatever `events.after_host_commit` says (doctrine/dbal 4; on DBAL 3 they leave at once).

### With UserKit (2.5.0)

With nowo-tech/user-kit-bundle installed, the vault registers itself in UserKit's GDPR tools; nothing to wire:

- eraser `vault` (priority 180, before your identity eraser at 100 or below) → `VaultPersonalDataEraserInterface::erase()`
  with the string form of the user's `getId()`;
- export section `vault` (priority -50) → the metadata of `VaultPersonalDataExporterInterface::export()`, one
  sub-section per erasure step (never a value, a ciphertext, a token or a hash).

UserKit runs its erasers in one transaction: on the same connection the vault erasure joins it, so a later eraser that
fails rolls the vault rows back too, and its events wait for UserKit's commit. UserKit's `personal_data.enabled` must
be on. Turn the bridges off when you erase and export the vault yourself (a provider of yours with the section key
`vault` would make UserKit's export fail with a duplicate-section error):

```yaml
nowo_vault:
    personal_data:
        user_kit: false     # default true (registered when UserKit is installed)
        batch_size: 500     # ids per statement of the erasure
```

## Decrypted export

With `export.enabled`, a user downloads a decrypted copy of their **personal** vault (never items shared with them,
never scope items) from `/tools/vault/export`, after a fresh re-authentication that your application performs. Implement
the hook:

```php
final class ReauthChecker implements VaultReauthenticationProviderInterface
{
    public function proofFor(VaultReauthContext $context): ?VaultReauthProof
    {
        $userId = $context->user?->id;      // VaultUserRef of the signed-in user ($context->user->user() is your user object)
        if ($userId === null) {
            return null;
        }
        // Your re-auth step (not the login), bound to the session: $context->sessionBinding() is hash('sha256', session id).
        $step = $this->reauthStore->latest($userId, $context->sessionBinding(), $context->purpose);
        if ($step === null || $step->used) {
            return null;
        }

        return new VaultReauthProof(
            $step->id,
            $userId,
            $context->purpose,              // 'nowo_vault.personal_export'
            $step->verifiedAt,              // when the factors below were verified in that step
            $step->factors,                 // e.g. ['password', 'totp']
            $this->mfa->isEnrolled($userId), // read from the store, not from the session
            $context->sessionBinding(),
        );
    }

    public function consumeProof(VaultReauthProof $proof): void
    {
        $this->reauthStore->markUsed($proof->proofId);
    }

    public function reauthenticationUrl(VaultReauthContext $context, string $returnTo): string
    {
        return $this->urls->generate('app_reauth', ['purpose' => $context->purpose, 'return' => $returnTo]);
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
| `PERSONAL_DATA_ERASED` | A user's vault data was erased (1.16.0; user id, counts; 2.5.0: inside UserKit's (or your) transaction it waits for the outermost commit and is dropped on rollback, doctrine/dbal 4) |
| `ITEM_CREATE_CHECK` | Refuse a new item, e.g. a plan's item limit (2.5.0; ids, type, lazy locked `itemCount()`; see [Item limits](#item-limits-250)) |
| `EXTENSION_TOKEN_REVOKED` | Extension tokens revoked through `VaultExtensionTokenCommandsInterface` (2.5.0; user id, token ids, reason; after the commit) |
| `GRANT_POLICY_CHECK` | Veto a grant in `grantAs()` with a reason code (1.16.0) |
| `GRANT_CREATED`, `GRANT_UPDATED`, `GRANT_REVOKED`, `GRANT_EXPIRED` | Grant audit trail (`VaultGrantChangeEvent`: ids, permission, expiry, actor, reason; after the flush) |
| `ITEM_CHANGED` | Item activity (`VaultItemChangeEvent`: created, updated, trashed, restored, purged, moved; `secretChanged`) |
| `FOLDER_CHANGED` | Folder activity (`VaultFolderChangeEvent`: created, renamed, trashed, restored, purged; ids only, 1.19.0) |
| `TAG_CHANGED` | Tag activity (`VaultTagChangeEvent`: created, deleted; ids only, 1.19.0) |

Since 1.19.0 every hook event that carries the user also gives it as a framework-free `VaultUserRef`
(`getUserRef()` / `getUserId()`; `getViewerRef()`, `getActorRef()`, `getGrantedByRef()`, `getListSubjectRef()` on the
list, policy and share events) and the ids of its resources (`getItemId()`, `getFolderId()`, `getOwnerKey()`,
`VaultItemListResultEvent::getItemIds()`). The `UserInterface` getters were removed in 2.0 (deprecated since 1.19).

The change events (`ITEM_CHANGED`, `FOLDER_CHANGED`, `TAG_CHANGED`, `GRANT_*`) are dispatched after the vault's commit.
Inside a transaction your own code opened on the vault's connection (the vault's unit is then a savepoint) they leave
when the vault's unit ends, unless you opt in to `events.after_host_commit: true` (2.1.0, the default from 3.0): then they
wait for your commit, are dropped if you roll back (a rolled-back savepoint drops the events written inside it), and
committed events are dispatched at the next idle point: the next vault change outside a transaction, or the end of the
request, Messenger message or console command, never inside the commit call. doctrine/dbal 4 only (see
[CONFIGURATION](CONFIGURATION.md#event-timing-events)). With the option on:

- a listener of a released event runs after the data is committed: its exception is **logged and not rethrown** (it
  must not turn a committed write into an error of an unrelated call or of the boundary), and the other released
  events still run;
- in a long-running loop (a console command or a worker handling many items in one transaction stream) the events
  wait until the next vault change outside a transaction or the end of the command; commit and let the loop go idle,
  or keep the option off, if listeners must react sooner;
- the start of every main request empties what is still held (nothing of one request is dispatched in the next).

## Lists and pagination

The items, shared and trash pages are paginated (`?page=N`, `nowo_vault.pagination.per_page`, default 50; see [CONFIGURATION.md](CONFIGURATION.md#pagination)). Own / folder / tag / trash lists page in the database (Doctrine `Paginator`, tags fetch-joined, so the page costs a constant number of queries); shared items are loaded through the grant ids and sliced in PHP; search results stay capped at 50 and are not paginated. A custom `VaultItemRepositoryInterface` keeps working: implement `VaultItemPaginatorInterface` to page in the database, otherwise the lister slices the full list. Items returned by a `VaultEvents::ITEM_LIST_QUERY` override are used as given (your listener owns paging).

The browser extension `/logins` lookup decrypts at most `browser_extension.max_login_items` login items (default 1000, own items first, most recently updated first) and logs a warning when the limit truncates the scan.

## Twig overrides

The bundle registers **`@NowoVaultBundle/`**. Application files under **`templates/bundles/NowoVaultBundle/`** win over vendor (`TwigPathsPass`).

**Host note without an override (1.18.0):** set `web_ui.host_note_template` to a template of yours; it is included
under the page header of the vault home and of the items list (block `vault_host_note`, variables `page` and
`routes`).

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
| `vault/_password_generator.html.twig` | Password generator fragment (loaded when the remote dialog opens) |
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

## Standard Symfony host

The default, zero configuration path: `user_class` is your Doctrine user entity (attributes or XML) in the vault's
entity manager, the vault rows hold Doctrine relations (`ManyToOne`) to it (`creator_id`, `created_by_id` and `user_id`
are foreign keys to your users table), the vault entities are mapped from their attributes, and your security user is
the vault user.

```yaml
nowo_vault:
    user_class: App\Entity\User
    encryption_key: '%env(VAULT_ENCRYPTION_KEY)%'
```

If your security user is not that entity (a JWT user, a wrapper, a read model carrying the entity id), list its class:
it is stored as `getReference(user_class, getId())`, no query.

```yaml
nowo_vault:
    user_class: App\Entity\User
    accepted_user_classes: [App\Security\SecurityUser]
```

Customise access with services named in the configuration (`security.access_checker`, `team_membership_resolver`,
`scopes.access_resolver`, `export.reauth_checker`, `health.policy_resolver`, `health.breach_checker`): they implement
the framework-free interfaces (`VaultAccessPolicyInterface`, `VaultTeamMembershipProviderInterface`,
`VaultScopeAccessProviderInterface`, `VaultReauthenticationProviderInterface`; the 1.x interfaces taking
`UserInterface` were removed in 2.0). Inside your adapter `$ref->user()` is your security user.

```php
final readonly class TeamsFromMyDirectory implements VaultTeamMembershipProviderInterface
{
    public function __construct(private TeamRepository $teams) {}

    public function teamIdsOf(VaultUserRef $user): array
    {
        return $user->id === null ? [] : $this->teams->idsOfMember($user->id);
    }
}
```

**Writes and your unit of work.** The vault reads and writes its rows through its own entity manager on the
connection of `database.entity_manager`: a vault write never flushes your pending changes and a failed vault write
never closes your manager. Load vault rows through the vault repositories and services (not through your own
manager), change them through the vault services, and reference them by id from your entities, or with
`$yourEntityManager->getReference(VaultItem::class, $id)` (see [UPGRADING](UPGRADING.md#private-manager-only)). A
transaction you open on the connection includes the vault's rows.

React to vault activity through the id-only events (`ITEM_CHANGED`, `FOLDER_CHANGED`, `TAG_CHANGED`, `GRANT_*`,
`ITEM_REVEALED`, `PERSONAL_DATA_ERASED`; see [Events](#events)).

## DDD / hexagonal host

The vault is its own bounded context: it owns its entities (items, folders, tags, grants, settings, extension tokens),
its crypto and its pages. Your domain never extends a vault class or uses a vault trait; it only tells the vault who
the user is, through ports that take framework-free value objects (no Symfony or Doctrine type in what you
implement). Opt-in:

```yaml
nowo_vault:
    user_class: App\Infrastructure\Security\SecurityUser   # its getId() is stored
    user_mapping: id                                         # string user columns, no relation, no foreign key
    database:
        mapping_type: xml                                    # the shipped XML mapping (or register_mapping: false)
    security:
        access_checker: App\Infrastructure\Vault\VaultAccess
    team_membership_resolver: App\Infrastructure\Vault\VaultTeams
    scopes:
        enabled: true
        access_resolver: App\Infrastructure\Vault\VaultScopes
```

```yaml
# config/services.yaml: how the vault loads a user by id (extension tokens, scope grantee checks, GDPR)
Nowo\VaultBundle\Support\VaultUserDirectoryInterface: '@App\Infrastructure\Vault\VaultUserDirectory'
```

```php
final readonly class VaultUserDirectory implements VaultUserDirectoryInterface
{
    public function __construct(private UserRepository $users) {}

    public function findUser(string $id): ?VaultUserRef
    {
        $user = $this->users->ofId(UserId::fromString($id));
        // Null for an unknown, disabled, locked or foreign user: no Symfony UserChecker sees the vault's stand-in.
        if ($user === null || !$user->isActive()) {
            return null;
        }

        // Exactly the requested id (the vault refuses an answer for another id), with the roles of your own model.
        return VaultUserRef::ofId($id, $user->email(), $user->roleNames());
    }
}

final readonly class VaultAccess implements VaultAccessPolicyInterface
{
    public function allowsAccess(?VaultUserRef $user = null): bool { return $user?->hasRole('ROLE_USER') ?? false; }
    public function allowsCreate(?VaultUserRef $user = null): bool { return $user?->hasRole('ROLE_USER') ?? false; }
    public function allowsList(?VaultUserRef $user = null): bool { return $user?->hasRole('ROLE_USER') ?? false; }
    public function allowsRevoke(?VaultUserRef $user = null): bool { return $user?->hasRole('ROLE_USER') ?? false; }
}
```

The ports you implement or call:

| Port | Receives / returns | Config key or alias |
|---|---|---|
| `Security\VaultAccessPolicyInterface` | `?VaultUserRef` | `security.access_checker` |
| `Security\VaultTeamMembershipProviderInterface` | `VaultUserRef` → team ids | `team_membership_resolver` |
| `Security\VaultScopeAccessProviderInterface` | `VaultUserRef`, scope key → `?VaultScopeAccess` | `scopes.access_resolver` |
| `Security\VaultReauthenticationProviderInterface` | `VaultReauthContext` → `?VaultReauthProof` | `export.reauth_checker` |
| `Security\VaultPasswordPolicyResolverInterface` | `VaultOwner` → `?VaultPasswordPolicy` | `health.policy_resolver` |
| `Security\VaultBreachCheckerInterface` | password → `?bool` | `health.breach_checker` |
| `Support\VaultUserDirectoryInterface` | user id → `?VaultUserRef` | alias |
| `Service\VaultHealthReportProviderInterface` | `VaultUserRef` → `VaultHealthReport` | you call it |
| `Service\VaultPersonalDataEraserInterface` / `…ExporterInterface` | user id | you call them |
| `Service\VaultItemCommandsInterface`, `…FolderCommandsInterface`, `…GrantCommandsInterface`, `…TagCommandsInterface` | `VaultUserRef` + ids → views (2.1.0) | you call them |
| `Service\VaultItemQueryInterface` | `VaultItemCriteria` / ids → `VaultItemPage` / `VaultItemView` (2.1.0) | you call it |
| `Service\VaultAccessCheckInterface` | `VaultUserRef` + id + `VaultAccessAction` → bool (2.1.0) | you call it |

### Host ports by id (2.1.0)

Your application layer writes, reads and checks vault data without touching an entity, a `UserInterface` or DQL. Every
port takes a `VaultUserRef` (usually `VaultUserRef::ofId($userId)`, looked up again through your directory) and ids, and
returns read models from `Nowo\VaultBundle\View` (`VaultItemView`, `VaultFolderView`, `VaultGrantView`,
`VaultItemPage`) that carry ids, scalars and dates only: never a payload value, the ciphertext or a secret hash.

```php
use Nowo\VaultBundle\Dto\VaultItemCriteria;
use Nowo\VaultBundle\Enum\VaultItemType;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultItemCommandsInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;

final readonly class SeedOrganisationVault
{
    public function __construct(
        private VaultItemCommandsInterface $items,
        private VaultItemQueryInterface $query,
        private VaultAccessCheckInterface $access,
    ) {}

    public function __invoke(string $userId, string $orgKey): ?string
    {
        $actor = VaultUserRef::ofId($userId);
        if ($this->query->count(VaultItemCriteria::forScope($orgKey)->withTitle('Wi-Fi')) > 0) {
            return null;
        }

        $item = $this->items->createInScope($actor, $orgKey, VaultItemType::SecureNote, 'Wi-Fi', ['notes' => '…'], null, ['office']);

        return $this->access->canAccessItem($actor, $item->id, VaultAccessAction::Edit) ? $item->id : null;
    }
}
```

- **Commands** (`VaultItemCommandsInterface`: `create`, `createInScope`, `update`, `move`, `retag`, `moveToTrash`,
  `restore`, `purge`; `VaultFolderCommandsInterface`: `create`, `createInScope`, `rename`, `moveToTrash`, `restore`,
  `purge`; `VaultGrantCommandsInterface`: `grant`, `revoke`, `listForResource`; `VaultTagCommandsInterface`: `delete`)
  re-check the actor's access at the call with the same rules as the vault pages, run in one vault write unit (one
  transaction; a savepoint inside yours) and emit the usual change events. A refusal is one
  `VaultAccessDeniedException` whether the actor is unknown, the resource does not exist or access is denied (an id
  never confirms that a resource exists); a folder of another owner is a `VaultOwnerException`; sharing rules answer
  `VaultGrantRefusedException` with its reason code; a lock conflict leaves as `TransientStorageFailure` (retry).
  `update()` takes the `editToken` of the view you read and refuses with `VaultItemChangedException` a save that would
  overwrite a change saved meanwhile. The payload you pass replaces the stored one as a whole.
- **Query** (`VaultItemQueryInterface`: `find`, `count`, `findById`, `findByIds`) is a system read with no access check:
  filter by owner (`VaultItemCriteria::forUser($id)`, `forScope($key)` or `forOwnerKey('user:…' | 'scope:…')`), type,
  folder (or none), tag, exact title (byte for byte), title fragment, trash state, sort and page (at most 500 rows).
  Where the database already compares the owner byte for byte and there is no exact title (a scope, or the id
  mapping on a `creator_id` column with a binary NO PAD collation on MySQL / MariaDB, a deterministic one on
  PostgreSQL outside a transaction, or SQLite), the page is read with SQL `LIMIT`/`OFFSET` and the total with SQL
  `COUNT` (2.1.1); the page's owners are still re-checked in PHP. The page and the total are then two statements: call
  `find()` inside your own transaction (with a suitable isolation level) if a write landing between them matters.
  Other platforms, `char(n)` / `citext` columns and a failed catalog read keep the exact path. A column altered by hand
  on SQLite (`NOCASE`, `RTRIM`) is not detected: the page is still exact, but `count()` and a full page's total would
  include look-alike rows. Otherwise (relation mapping, an exact title, a column that does not compare
  exactly) it reads one scalar row per candidate of the owner, re-checks the owner id and an exact title byte for byte
  in PHP, then loads the page. Either way items, page and total agree. With the relation mapping and an integer user
  key, only the canonical decimal id (`5`, not `05`) matches. Decide what a user may see with your own rules or with the access port
  first.
- **Access** (`VaultAccessCheckInterface`: `canAccessItem`, `canAccessFolder`, `isItemReadOnly`, `itemPermissions`,
  `scopeAccess`) answers `false` / null for an unknown user or resource.
- **Uniform refusals.** Unknown, trashed and denied resources give the same answer: `VaultAccessDeniedException` for
  the item and folder commands, `VaultGrantRefusedException` (`actor_cannot_share`) for an unknown resource or grant
  in `grant()` / `revoke()`, and null for every requested id in `itemPermissions()`. A scope command checks the scope
  membership before it looks at a folder. Ids compare byte for byte (`ABC` never reaches `abc`).
- **Form rules are yours to apply.** The command ports write what you pass: they do not run the item form's rules
  (the password policy with `health.policy.enforce_on_save`, the attachment size limit, the payload keys of each item
  type, tag and title normalisation beyond the length checks). Validate the payload in your use case first.
- **Organising is the owner's.** `move()`, `retag()` and folder `rename()` need the personal owner, or a scope member
  who may Share the resource; a scope item only moves into a folder the actor may use (the folders the item pages
  offer them), so a move never extends a folder's grants past what the actor could share.
- **Feature roles are yours to gate.** The ports apply the per-resource rules only (ownership, scope membership,
  grants, the access-check and read-only events). The role checks of the vault pages (`security.*_roles` or your
  `VaultAccessPolicyInterface`: access, create, list, revoke) are UI gates: check them in your use case before calling
  a port when your users need them.

The entity / `UserInterface` signatures of `VaultItemCreator`, `VaultItemUpdater`, `VaultFolderService`,
`VaultTagService`, `VaultTrashService`, `VaultGrantService::grantAs()` / `revokeAs()` and `VaultAccessGuard` stay for the
vault's own pages and standard hosts; they are deprecated for host code and become internal in 3.0.

Your directory is the only source of trust for users known by id: the identity and role names the vault uses come
from its answer, and it must answer null for a user who must not use the vault (unknown, disabled, locked, another
identity domain), because no Symfony `UserChecker` sees the vault's stand-in user. When your application layer calls
the vault with a reference by id (`VaultUserRef::ofId($userId)`, e.g. a health report or a browser-extension
authenticator answer), the vault only takes the id from it and looks it up through the directory again; the identifier
and roles you put in the reference are ignored, so never build one from untrusted input expecting it to carry
permissions. Grantees are string ids (`GranteeType` + id), so sharing with your teams needs no relation to your team
entity.

With `user_mapping: id`:

- the user columns are `VARCHAR(128)` holding the id (no foreign key, no join to your users table: the bundle's
  metadata listener replaces the relation with a plain column), indexed, and compared byte for byte: on MySQL /
  MariaDB they (and `vault_grants.grantee_id`) use a binary NO PAD collation (since 1.19.3: `utf8mb4_0900_bin` on
  MySQL 8.0.17+, `utf8mb4_nopad_bin` on MariaDB; the PAD SPACE `utf8mb4_bin`, with a logged warning, on MySQL 5.7 or
  a `server_version` without its patch number) and on SQL Server `Latin1_General_100_BIN2` (PostgreSQL, SQLite and
  Oracle already compare byte-exact). Keep that collation in your migrations: the access
  decisions on grants, "shared with me", the decrypted export, the GDPR export / erasure and the token revocation stay
  exact without it, but lists, counts and search rely on it. Set `doctrine.dbal.server_version` when the database is
  not reachable at `cache:warmup` (the listener needs the platform);
- an id is `(string) getId()` as is: at most 128 bytes, valid UTF-8, no leading or trailing whitespace and no control,
  zero-width / format, space or other default-ignorable character (such users are refused, never trimmed, normalised
  or truncated; the relation mapping keeps the 1.16 rules); `5` and `"5"` are the same user, `"05"` and
  `"UserA"` / `"usera"` are not. Normalise ids in your own user model if you need case-insensitive identities;
- the entities return a `VaultUserId` (`getId()`) for `getOwner()->getUser()`, `getCreatedBy()` and `VaultExtensionToken::getUser()`;
  prefer the id accessors (`getCreatorId()`, `getCreatedById()`, `getUserId()`) in your code;
- deleting a user deletes nothing in the vault: call `VaultPersonalDataEraserInterface::erase($userId)` from your
  "user deleted" handler (see [Personal data](#personal-data)).

**In both paths:**

- only `user_class` instances, `VaultUserId`s, references your code gives by id and `accepted_user_classes` instances
  (with a usable `getId()`) are vault users; any other signed-in user is refused on the vault pages (403), by the
  repositories, the access guard (`VaultAccessGuard`: no ownership, grant or scope membership), the field revealer,
  grants (`grantAs()`), the personal export and the browser extension login, also when your own code calls those
  services, so an `Admin#5` never matches a `Customer#5`;
- replace how users are turned into stored values by aliasing `VaultUserReferenceResolverInterface`;
- the services are stateless and work in FrankenPHP worker mode with the kernel kept alive and not reset (see
  [FRANKENPHP-WORKER-AUDIT](FRANKENPHP-WORKER-AUDIT.md)).

## Item limits (2.5.0)

Every new item (the personal and scope *New item* pages, `VaultItemCommandsInterface::create()` /
`createInScope()`) first dispatches `VaultEvents::ITEM_CREATE_CHECK` (`VaultItemCreateCheckEvent`). Without a listener
nothing is limited. A plan limit:

```php
#[AsEventListener(event: VaultEvents::ITEM_CREATE_CHECK)]
public function __invoke(VaultItemCreateCheckEvent $event): void
{
    $limit = $this->plans->vaultItemLimit($event->getOwnerKey()); // 'user:<id>' or 'scope:<key>'
    if ($limit !== null && $event->itemCount() >= $limit) {
        $event->deny('plan_limit');
    }
}
```

- The event carries ids only: owner key, owner user id or scope, actor id, item type; never the title or a value.
- `itemCount()` counts the owner's stored items, trash included, once per event and only when called. Inside the
  item's write unit it is serialised per owner (PostgreSQL advisory lock, `SELECT … FOR UPDATE` elsewhere), so two
  creations racing for the last place never both pass (see [CONCURRENCY.md](CONCURRENCY.md#item-limits)).
- A refusal shows the form again (422) with a generic message, nothing written; the commands throw
  `VaultItemCreateDeniedException` (`getReason()`: your code, `[a-z0-9_.-]`, at most 64 characters).

## Browser extension tokens per user (2.5.0)

`VaultExtensionTokenCommandsInterface` lists and revokes one user's extension tokens, for an *Account → Security* page:

```php
$tokens = $this->vaultTokens->listForUser(VaultUserRef::ofId((string) $user->getId())); // list<VaultExtensionTokenView>
$this->vaultTokens->revoke($ref, $tokenId);   // false: not one of this user's tokens (also: unknown or already gone)
$this->vaultTokens->revokeAll($ref);          // sign out every device; returns how many
```

Views hold the id and the dates (created, expires, last used), never the token or its hash. Another user's token id
answers `false`, like an unknown id. A revocation emits `EXTENSION_TOKEN_REVOKED` (`VaultExtensionTokenRevokedEvent`:
user id, token ids, reason `revoked` / `revoked_all`) after the commit. The bundle renders no page for it: protect your
own revoke action with CSRF.

## Browser console

The bundle's script logs one line when it loads (`📦 [vault] script loaded, build: 2026-11-23 14:05 UTC`) so a developer can tell which build a page runs. To silence it, set `<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'` before the script loads; nothing is printed then. The default is unchanged.
