# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.16.0] - 2026-11-05](#1160---2026-11-05)
- [[1.15.2] - 2026-11-05](#1152---2026-11-05)
- [[1.15.1] - 2026-10-30](#1151---2026-10-30)
- [[1.15.0] - 2026-10-30](#1150---2026-10-30)
- [[1.14.0] - 2026-10-29](#1140---2026-10-29)
- [[1.13.0] - 2026-10-28](#1130---2026-10-28)
- [[1.12.0] - 2026-10-28](#1120---2026-10-28)
- [[1.11.1] - 2026-10-28](#1111---2026-10-28)
- [[1.11.0] - 2026-10-28](#1110---2026-10-28)
- [[1.10.0] - 2026-10-28](#1100---2026-10-28)
- [[1.9.0] - 2026-10-26](#190---2026-10-26)
- [[1.8.2] - 2026-10-24](#182---2026-10-24)
- [[1.8.1] - 2026-10-18](#181---2026-10-18)
- [[1.8.0] - 2026-10-13](#180---2026-10-13)
- [[1.7.0] - 2026-10-13](#170---2026-10-13)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-09](#150---2026-10-09)
- [[1.4.8] - 2026-09-27](#148---2026-09-27)
- [[1.4.7] - 2026-08-24](#147---2026-08-24)
- [[1.4.6] - 2026-08-20](#146---2026-08-20)
- [[1.4.5] - 2026-08-19](#145---2026-08-19)
- [[1.4.4] - 2026-08-19](#144---2026-08-19)
- [[1.4.3] - 2026-08-19](#143---2026-08-19)
- [[1.4.2] - 2026-08-19](#142---2026-08-19)
- [[1.4.1] - 2026-08-18](#141---2026-08-18)
- [[1.4.0] - 2026-08-04](#140---2026-08-04)
- [[1.3.0] - 2026-08-03](#130---2026-08-03)
- [[1.2.0] - 2026-07-30](#120---2026-07-30)
- [[1.1.5] - 2026-07-29](#115---2026-07-29)
- [[1.1.4] - 2026-07-22](#114---2026-07-22)
- [[1.1.3] - 2026-07-16](#113---2026-07-16)
- [[1.1.2] - 2026-07-13](#112---2026-07-13)
- [[1.1.1] - 2026-07-08](#111---2026-07-08)
- [[1.1.0] - 2026-07-05](#110---2026-07-05)
- [[1.0.0] - 2026-07-04](#100---2026-07-04)

## [Unreleased]

Target **1.17.0** (minor): strict DDD / hexagonal hosts can use the vault (architecture bar rule 2). The standard path
(Doctrine relations to `user_class`) is the default and does not change; no schema change for it.

### Added

- **`user_mapping: relation|id`** (default `relation`). With `id`, the vault rows store the host user id in a string
  column (`creator_id`, `created_by_id`, `user_id`, length 128): no relation and no foreign key to the host users
  table, `user_class` need not be a Doctrine entity (plain PHP security users, XML-mapped users in another entity
  manager, UUID / ULID / int ids). The entities then return a `VaultUserId` value object (`getId()`) for the creator,
  the audit creator and the token user, so ownership checks, owner keys and events work the same in both mappings.
- **`accepted_user_classes`** (default `[]`): security-user classes other than `user_class` that map to a vault user by
  `getId()` (a security user that is no Doctrine entity, a wrapper, a read model). With the relation mapping they are
  stored as `getReference(user_class, id)`; previously such a user could not create anything (Doctrine refused the
  object). A user id given as a string to an entity constructor also becomes that reference.
- Ports `VaultUserReferenceResolverInterface` (default `DoctrineVaultUserReferenceResolver`) and
  `VaultUserLocatorInterface` (default `VaultUserLocator`, now reading through the entity manager that maps
  `user_class`). With the id mapping, browser extension tokens resolve their user through the locator: alias it when
  your users are no Doctrine entities.
- Id accessors next to the user accessors (rule 1): `VaultItem::getCreatorId()` / `getCreatedById()`,
  `VaultFolder::getCreatorId()` / `getCreatedById()`, `VaultTag::getCreatorId()`, `VaultGrant::getCreatedById()`,
  `VaultExtensionToken::getUserId()`.
- Tests on both host kinds: the standard attribute host (relation), a relation host with a non-entity security user
  (`accepted_user_classes`) and a strict DDD host (id mapping, plain-PHP users with UUID ids, no host entity mapped,
  locator aliased by the host): pages, folders, tags, grants, export, erasure and extension tokens.

### Security

- **Cross-class owner confusion (pre-existing, affects 1.0.0 to 1.16.x; a security advisory follows this release).**
  Ownership was compared by the string form of `getId()` only, ignoring the user's class. So before 1.17.0 a signed-in
  user of another class with a colliding id (an `Admin#5` next to a `Customer#5`, typically in the same entity manager,
  which is what lets the lists enumerate the other user's items; the per-item checks matched any user object with that
  `getId()` on the vault firewall) was treated as the owner of the other user's personal vault: list, open, reveal,
  edit, delete, share and, since 1.16.0, export. Now a signed-in user that is neither a `user_class` instance nor
  listed in `accepted_user_classes` (with a usable `getId()`) fails closed: refused on the vault pages (403), by the
  repositories, the access guard (`VaultAccessGuard`: no ownership, grant or scope membership), the field revealer
  (`VaultAccessDeniedException`), grants (`grantAs()`), the personal export and the browser extension login, also when
  host code calls those services directly. Standard hosts whose only security user is the `user_class` entity are not
  affected.

- **Id mapping compares ids byte for byte (LQ-V-M1, LQ-V-P2-M1).** The user id columns and the grant grantee id
  (users and teams) get a binary collation where the platform defaults to a case- or accent-insensitive one
  (`utf8mb4_bin` on MySQL / MariaDB, `Latin1_General_100_BIN2` on SQL Server; PostgreSQL, SQLite and Oracle already
  compare byte-exact), so `usera` never matches `UserA`, neither as an owner nor as a grantee. Whatever the column
  collation, the grant reads behind access decisions (`findActiveForGrantees()`, `findByGrantee()`, `findOne()`,
  "shared with me"), the decrypted personal export (per item), the GDPR export and erasure (grants received
  included) and the token revocation keep only the rows whose stored id is identical (`===`). In id mapping, ids
  longer than 128 bytes, with leading / trailing whitespace, or with control, format (zero-width, bidi), separator or
  other default-ignorable characters, and invalid UTF-8 are refused (never trimmed, normalised or truncated); the
  default relation mapping keeps the 1.16 id rules. On a schema whose `grantee_id` still folds case, a grant upsert
  that hits another grantee's unique key is refused (`VaultGrantRefusedException`, `grantee_not_allowed`) instead of
  a 500. The id columns
  are indexed (`<table>_<column>_idx`). In id mapping the bundle needs the database platform when it builds its
  metadata: set `doctrine.dbal.server_version` if the database is not reachable at `cache:warmup` (it now fails loudly
  instead of caching metadata without the collation). CI runs these checks on MySQL 8.0, MariaDB 11.4 and
  PostgreSQL 17.
- In id mapping, a user returned by the `VaultUserLocatorInterface` for an extension token must be a vault user too.
- `VaultFieldRevealer` refuses non-vault users but does not check ownership or grants: callers check
  `VaultAccessGuard::canAccessItem(…, View)` first, as the bundle's routes do (documented).

### Changed

- New vault rows normalise their user columns at `prePersist` (`VaultUserColumnsListener`): a `user_class` instance
  is left as is; an accepted user becomes a reference (relation) or its id (id mapping); any other user is refused.
- `DoctrineOrmVaultExtensionTokenRepository::findByUser()` compares the token's user column directly (no join to the
  users table).

## [1.16.0] - 2026-11-05

Scope-owned vaults, temporary grants, metadata catalogue, reveal / copy / attachment download, password health and
policies, personal-data exporter / eraser and the decrypted personal export (1.16.0 contract, gaps 1 to 7). Requires a schema migration, see [UPGRADING](UPGRADING.md#unreleased).

### Added

- **Scope-owned vaults (gap 1):** items, folders and tags can be owned by a host scope (`owner_scope`, e.g.
  `project-<uuid>`) instead of a user. New `VaultOwner` value object (`user()` / `scope()`, `key()`, `equals()`),
  `VaultItem::forScope()`, `VaultFolder::forScope()`, `VaultTag::forScope()`, `getOwner()`, `getOwnerScope()`,
  `getCreatedBy()` (audit only, `ON DELETE SET NULL`). The host decides membership through
  `VaultScopeAccessResolverInterface::resolve($user, $scope)` → `VaultScopeAccess` (baseline permission, `catalogue`,
  `health`, `create`) or `null` for a non-member; the answer is memoized per request only
  (`RequestScopedScopeAccessCache`, `kernel.reset`). Config `scopes.enabled` / `scopes.access_resolver` (compile error
  when enabled without a resolver). Effective permission = owner (personal) or the scope baseline, plus active grants on
  the item and on its folder when the folder has the same owner; a non-member is denied everything, grants included.
- **Scope pages** (registered only with `scopes.enabled`): `scope_items` (`GET /tools/vault/scopes/{scope}`),
  `scope_item_new` (`/tools/vault/scopes/{scope}/items/new/{type}`), `scope_folder_create` (`POST …/folders`). The scope
  key comes from the route (`[a-z0-9][a-z0-9_-]{0,127}`), is re-validated on every request and answers the deny status
  for a non-member. Services for hosts that render their own pages: `VaultScopeItemLister`,
  `VaultItemCreator::createInScope()`, `VaultFolderService::createInScope()`, scope tags in `VaultTagService`. New
  template `vault/scope_items.html.twig`; the item form shows a catalogue warning on the title of scope items and a
  *Hide from the catalogue* choice for scope admins.
- **Temporary grants (gap 2):** `vault_grants.expires_at`; a grant is active while `now < expiresAt`, checked on every
  read path (guard, repository queries, *Shared with me*, extension `/logins`, field reveal) against the injected clock,
  with no purge lag. `VaultGrantService::grantAs($actor, …, ?$expiresAt)` re-checks Share at the call, refuses self
  grants, unlisted grantees (fail closed), non-member user grantees of scope resources, expiries outside
  `grants.temporary.min_ttl` / `max_ttl` and permissions above `grants.temporary.max_permission` (`read` by default,
  `admin` refused by the config tree), then dispatches the vetoable `GRANT_POLICY_CHECK`; a temporary grant never
  replaces or upgrades a permanent one (`unchanged` / `would_replace_permanent`). Race-safe writes (unique-violation
  retry, conditional `UPDATE` / `DELETE` with affected-row checks). `revokeAs($actor, $grant)`. Optional expiry choice in
  the share form (`grants.temporary.ui`), expiry and *Expired* badge on the share page.
- **`nowo:vault:grants:purge-expired`** (`--batch-size`, `--limit`, `--dry-run`; prints `{"expired":n,"dry_run":b}`):
  deletes expired grants with a conditional delete and emits `GRANT_EXPIRED` once per row (concurrent purgers and
  extensions are safe). `VaultGrantExpiryService`.
- **`nowo:vault:schema:check-downgrade`:** read-only counts of the rows that block a rollback to 1.15.x.
- **Events (ids only, after the flush):** `GRANT_POLICY_CHECK` (`VaultGrantPolicyCheckEvent`, vetoable with a code),
  `GRANT_CREATED`, `GRANT_UPDATED` (previous permission / expiry), `GRANT_REVOKED` (reason `manual`, `resource_purged`),
  `GRANT_EXPIRED` (`VaultGrantChangeEvent`), `ITEM_CHANGED` (`VaultItemChangeEvent`: created, updated, trashed, restored,
  purged, moved; `secretChanged`).
- **`vault_items.secret_changed_at`:** set on create and when a secret field (or the attachment) changes; title, tag,
  folder and rotation changes leave it alone.
- **Metadata-only catalogue (gap 3):** `VaultCatalogue::page($viewer, $scope, VaultCatalogueQuery)` →
  `VaultCataloguePage` of `VaultCatalogueEntry` (id, title, type, scope tag names, owner scope, `updatedOn` date only
  at 00:00 UTC, `viewerHasAccess`). Scalar DQL only: no `VaultItem` (and no ciphertext) is hydrated; constant number of
  queries per page. Members need `VaultScopeAccess::$catalogue`; `CATALOGUE_ACCESS_CHECK`
  (`VaultCatalogueAccessCheckEvent`) can grant or deny. Hidden (`catalogue_hidden`) and trashed items are left out.
  Search (2 to 100 characters, wildcards literal), type and tag filters. Route `catalogue`
  (`/tools/vault/scopes/{scope}/catalogue`, with `scopes.enabled` and `catalogue.enabled`), template
  `vault/catalogue.html.twig` with the host block `vault_catalogue_row_actions` (receives `entry` only) for *Request
  access*.
- **Reveal additions (gap 4, on top of the 1.15.2 `item_reveal` route):** `purpose=copy` (audited as `ITEM_COPIED`);
  the JSON is the ZK-ready envelope `{"item","field","purpose","encoding":"plain","value"}`; `ITEM_FIELD_REVEAL_CHECK`
  (`VaultFieldRevealCheckEvent`) vetoes a field (answered 404); a throwing audit listener refuses the reveal with 503
  (no audit, no reveal); `reveal.revealable_fields` (per type; default the secret fields); `reveal.rate_limit` (30 per
  user per minute, 300 per hour, 10 per user, item and field per minute; 429 + `Retry-After` + `REVEAL_THROTTLED`);
  `reveal.auto_hide_seconds` (30). The reveal, copy and attachment responses carry `Referrer-Policy: no-referrer`,
  `X-Robots-Tag: noindex, nofollow` and `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`,
  and disable the profiler. `VaultFieldRevealer` service; `VaultRevealedValue` (dumps `***`, refuses serialization);
  `VaultDecryptionFailedException` (generic message, no previous exception; 503).
- **Attachment download:** `item_attachment` (`POST /tools/vault/items/{id}/attachment`, CSRF `vault_reveal_<id>`,
  View, field veto `attachment`, rate limited, `ITEM_ATTACHMENT_DOWNLOADED`), always `application/octet-stream` with a
  sanitised `Content-Disposition` file name (basename, no control characters or `"\/`, 100 characters,
  `attachment.bin` fallback, RFC 5987 `filename*`). Before 1.16.0 an attachment could be uploaded but never retrieved.
- **`vault.js`:** Copy button (clipboard, the value never enters the page), auto-hide of a revealed value, wipe when
  the tab is hidden or a dialog closes, attachment download, refuses an `encoding` other than `plain`.
- **Password health (gap 5):** `VaultHealthReporterInterface` (`forScope($viewer, $scope, ?$policy)`,
  `forPersonalVault($owner, ?$policy)`; server strategy `VaultHealthReporter`) → `VaultHealthReport` of
  `VaultHealthItemResult` (item id, title, `weak` + `weakReasons` codes, `reuseGroup` local to the report, `old` +
  `ageDays`, `breached`). Computed on demand in the request's process, over the owner's active login items the viewer
  may View (the others are counted in `skipped` and never compared); reuse uses a per-run random HMAC key that is wiped
  and never leaves the method; nothing is persisted. Weak uses `nowo-tech/password-strength-bundle`'s evaluator when
  installed (plus the class count), else a built-in length / character-class check; old uses `secret_changed_at`
  (falling back to `created_at`). Optional host `VaultBreachCheckerInterface` (`health.breach_checker`, null on failure).
  `health.max_items` (2 000; `VaultHealthTooLargeException`, 413), optional per-viewer cache of the flags only
  (`health.cache_ttl`, off). Scope access needs `VaultScopeAccess::$health` and the vetoable `HEALTH_ACCESS_CHECK`
  (`VaultHealthAccessCheckEvent`); `HEALTH_REPORT_GENERATED` (`VaultHealthReportGeneratedEvent`, counts only). Route
  `health` (`GET /tools/vault/scopes/{scope}/health`, with `scopes.enabled` and `health.enabled`), template
  `vault/health.html.twig`.
- **Password policies:** `VaultPasswordPolicy` (`minLength`, `minClasses`, `maxAgeDays`, `enforceOnSave`),
  `VaultPasswordPolicyResolverInterface::forOwner(VaultOwner)` (default `ConfigVaultPasswordPolicyResolver` reads
  `health.policy`; `health.policy_resolver` for a per-owner host resolver). With `enforceOnSave` the item form validates
  a typed login password (`VaultPasswordPolicyConstraint`; an empty value keeps the stored one and is not re-validated;
  the message names the missing rules, never the value).
- **Personal data (gap 6):** `VaultPersonalDataExporter::export(string $userId)` (metadata only: personal items with
  folder and tag names and timestamps, folders, tags, grants received (resource title only when the user can View it
  now), grants given, scope items created (id, scope, date), extension token dates; never a value, a ciphertext or a
  hash) and `VaultPersonalDataEraser::erase(string $userId, int $batchSize = 500)` → `VaultErasureResult`: in order,
  grants to the user and grants on the user's personal items and folders are deleted (`GRANT_REVOKED`, reason
  `erasure`), the remaining grants the user created are kept with `created_by_id = NULL`, then tag links, personal items
  (trash included), personal folders (children first) and tags are deleted, scope rows the user created get
  `created_by_id = NULL`, and extension tokens are deleted; one transaction per batch, no reliance on `ON DELETE
  CASCADE`, idempotent. `PERSONAL_DATA_ERASED` (`VaultPersonalDataErasedEvent`: user id, counts).
- **Decrypted personal export (gap 7):** `personal_export` (`GET /tools/vault/export`, warning page) and
  `personal_export_download` (`POST`, CSRF `vault_personal_export`), registered with `export.enabled`. The host
  implements `VaultReauthenticationCheckerInterface` (`getProof()`, `consume()`, `getReauthenticationUrl()`; required,
  compile error otherwise); `VaultReauthProofValidator` checks the `VaultReauthProof` against the bundle clock (user,
  purpose, `export.reauth_max_age` ≤ 900 s, 30 s skew, `export.accepted_primary_factors`, an
  `export.accepted_mfa_factors` factor when MFA is enrolled, the session binding) and keeps its own list of used proof
  ids (single use even with a buggy host). Then `export.max_items` / `export.max_bytes` (413), `export.cooldown_seconds`
  (429 + `Retry-After`), a decrypt pre-flight up to 500 items (503), the proof consumed, `ITEMS_EXPORTED`
  (`VaultItemsExportedEvent`; a throwing listener refuses with 503) and a `StreamedResponse` written in batches of 50
  with no temporary file: JSON (`nowo-vault-export` v1, attachments with `export.include_attachments`) or CSV (fixed
  header, every field quoted, cells starting with `= + - @`, tab or CR prefixed with `'` unless
  `export.csv_raw_values`). Personal items only (never shared-with-me or scope items). Headers:
  `Cache-Control: max-age=0, no-store, private`, `nosniff`, `Referrer-Policy: no-referrer`, the sandbox CSP,
  `X-Accel-Buffering: no`, a fixed `attachment; filename="vault-export-<date>.<format>"`; the profiler is disabled.
  Template `vault/export.html.twig`, form `VaultPersonalExportType`.
- `VaultItemRevealedEvent` gains `getPurpose()` (`reveal`, `copy`, `autofill`, `attachment`), `getOwnerKey()` and
  `getOccurredAt()`; the extension `/logins` events use purpose `autofill`.
- `VaultAccessGuard::resolvePermissionMap($user, $items)` (constant number of queries per list) and
  `resolveScopeAccess()`; `VaultGrantExpiryRepositoryInterface` (implemented by the Doctrine grant repository).

### Changed

- The guard loads the user's and teams' active grants on an item and its folder in **one** query (the redundant third
  lookup is gone with the Doctrine repository); folder grants apply when the folder has the item's owner (SEC-004 M1,
  generalised to scopes).
- Purging an item or folder deletes its grants in the same transaction (`GRANT_REVOKED`, reason `resource_purged`);
  1.15 left orphan grants.
- The share and revoke pages use `grantAs()` / `revokeAs()`; a refused grant shows a flash instead of saving.
- Grant log entries also carry `expires_at` and `actor_id` when set; refusals are logged at `notice` with the code.
- Every bundle response also gets `Expires: 0`.

### Deprecated

- `VaultItem::getCreator()`, `VaultFolder::getCreator()`, `VaultTag::getCreator()` (use `getOwner()`; they throw
  `VaultOwnerException` for scope rows).
- `VaultGrantService::grant()` and `revoke()` (use `grantAs()` / `revokeAs()`).

### Fixed

- **SEC-004 FQ-L3 (CSV formula injection):** the CSV export kept values exact on the premise that only the owner's data
  is in it, but a Write grantee can edit the owner's items (title, username, note, websites, …). Cells starting with
  `=`, `+`, `-`, `@`, tab or CR now get a leading `'` (OWASP); `export.csv_raw_values: true` keeps exact values. JSON is
  unchanged (exact).
- **SEC-004 FQ-I1:** the proof check, `consume()`, the bundle's used-proof list and the cooldown are one critical section
  per user when a Symfony Lock factory exists (`export.lock_factory`, default `lock.factory`; a busy lock answers 429
  `Retry-After: 1`). `symfony/lock` is suggested (and a dev dependency for the tests).
- **SEC-004 FQ-I13:** an explicit `export.lock_factory` that names no service now fails the container compile
  (`ExportLockFactoryPass`); only the default `lock.factory` may be absent (then the export runs without the lock).
- **SEC-004 FQ-I6:** `export.max_bytes` is checked against `SUM(LENGTH(ciphertext))` plus 512 bytes per item (the JSON
  keeps attachments as base64); it was `× 0.75`, which under-counted.
- **SEC-004 FQ-I2:** the health route had no throttle and every load decrypted every examined password and called the
  breach checker once per password. New `health.min_interval_seconds` (10: one computed report per viewer and owner per
  window, 429 + `Retry-After`, `VaultHealthThrottledException`) and `health.breach_cache_ttl` (86 400: breach answers
  cached per item id and secret change, never keyed by the value).
- **SEC-004 FQ-L2:** the browser extension `/logins` lookup returned the password without dispatching
  `ITEM_FIELD_REVEAL_CHECK`, so a host veto enforced on the web reveal was bypassed. It now dispatches the check for
  `password` (channel `extension`, purpose `autofill`); a vetoed password is sent as an empty string, flagged
  `VaultBrowserExtensionLoginMatch::$passwordWithheld` (and `"passwordWithheld": true` in the `/logins` JSON, FQ-I14)
  and left out of the `ITEM_REVEALED` field list. See [BROWSER-EXTENSION](BROWSER-EXTENSION.md#logins-response).
- **SEC-004 FQ-I5:** the `/logins` audit events (`ITEM_REVEALED`, purpose `autofill`) now carry the owner key.
- **SEC-004 FQ-I7:** the catalogue date is computed in UTC (it could be one day off on hosts whose PHP timezone is not
  UTC).
- **SEC-004 FQ-L1:** on the scope items page, a member without a baseline (explicit grants only) saw the names of every
  scope folder and tag. The page now lists only the folders (with their ancestors) and tags of the items the member may
  View, plus the folders they may Share. `VaultScopeItemLister::list()` also returns `visible` (null with a baseline).
- **SEC-004 FQ-L4 / FQ-I12:** the item page's folder select listed every scope folder, in view mode too. A member who
  cannot organise the item now gets only its current folder; one who can (Admin on the item) gets the folders they may
  use (all with a baseline, else those of their visible items with ancestors and those they may Share), plus the
  current one. New `VaultScopeItemLister::visibleFolders()`.

- Key rotation (`nowo:vault:reencrypt`) touched `updated_at` of every item, which reordered every list; it now uses
  `VaultItem::replaceCiphertextForRotation()`.

### Accepted deviations from contract vault-next v1.0

Accepted by the owner on 2026-11-05. The contract is the 1.16.0 design for symfony-beacon; where the code differs:

- **Reveal (gap 4):** the 1.15.2 route `/tools/vault/items/{id}/reveal/{field}` is kept, and `VaultItemRevealedEvent`
  is extended (`purpose`, `ownerKey`, `occurredAt`) instead of a new `VaultItemRevealEvent` class.
  `reveal.secret_fields` is not configurable (it would reopen the 1.15.2 HTML leak); `reveal.revealable_fields` defaults
  to the secret fields. There is no `browser_extension.logins_rate_limit` (the 1.15.2 shared secret-access budget covers
  `/logins`) and no `grants.require_grantee_list` (1.15.2 already fails closed).
- **Routes:** the new routes (scope pages, catalogue, health, attachment, export) are optional route keys enabled by
  the extension, so a loader built without the list keeps the 1.15 route set. The attachment download lives in
  `VaultRevealController`.
- **Tests:** two 1.15.2 assertions changed on purpose (the exact reveal JSON, now the envelope, and the
  `VaultItemRevealedEvent` property list).
- **Health (gap 5):** the scope page has no link to the health report (hosts link to the `health` route, as for the
  catalogue); unknown weak-reason codes are shown as is.
- **Personal data (gap 6):** to include a received grant's title only when the user can View the resource now, the
  exporter loads those resources and asks the access guard (it outputs the title only; every other section uses scalar
  queries). The eraser emits `GRANT_REVOKED` (reason `erasure`) through `VaultGrantService::dispatchChange()` after a
  conditional delete per row (no `revokeForSystem()` method) and writes with DQL / DBAL only (it never flushes the
  host's unit of work). The exporter and eraser services are private (inject them).
- **Decrypted export (gap 7):**
  - besides the host's `consume()`, the bundle keeps its own list of used proof ids in `export.cache_pool`, so a proof
    is single use even with a host that ignores `consume()`;
  - extra refusal reason codes: `primary_factor_missing`, `reauth_replayed`, `invalid_request`, `decrypt_failed`,
    `audit_failed`, `busy` (another export of the same user holds the lock, 429);
  - the GET page also redirects to the re-authentication when any proof check fails, and showing it does not consume
    the proof;
  - the stream detaches the entities of each batch instead of calling `EntityManager::clear()` (the host's user and
    pending entities stay managed);
  - the CSP is the shared sensitive set `default-src 'none'; frame-ancestors 'none'; sandbox` (stricter than the
    contract's); `Cache-Control: max-age=0, no-store, private` comes from the bundle response listener;
  - refusals answer an empty body with the status code (403, 413, 429, 503);
  - CSV joins `websites` with newlines and tags with `, `; `export.cooldown_seconds: 0` disables the cooldown;
  - Q-O7 (exact CSV values) was reversed after the SEC-004 audit (FQ-L3): CSV cells are neutralised by default, exact
    values are an opt-in (`export.csv_raw_values`).

[1.16.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.16.0

## [1.15.2] - 2026-11-05

Security hardening: no decrypted secret in the item HTML, `no-store` on every bundle response, fail-closed sharing without a grantee listener, `security.deny_status`, audited and rate-limited secret access. See [UPGRADING](UPGRADING.md#to-1152).

### Security

- **Decrypted secrets were rendered into the view and edit pages:** the item page decrypted the whole payload and filled every field, including passwords (`always_empty: false`), CVV, card PIN, card number, document number, secure note content and notes, so the values sat in the HTML source, the browser cache and any page capture. Secret fields now always render empty (`always_empty: true`, and the form data is cleared before rendering), with a "stored value hidden" placeholder; an empty submit keeps the stored value, and a **Clear stored values** choice empties it explicitly. Each value is fetched on demand by a new `POST /tools/vault/items/{id}/reveal/{field}` route (route key `item_reveal`; CSRF token `vault_reveal_<id>` in `X-CSRF-Token`; View access; secret fields of the item type only, anything else 404) through a Show / Hide button (`vault.js`), and wiped on `pagehide`.
- **No cache directives on vault responses:** every response of a bundle controller (manage pages, reveal JSON, runtime configuration, browser extension API) now carries `Cache-Control: no-store, private`, `Pragma: no-cache` and `X-Content-Type-Options: nosniff` (`VaultNoStoreResponseListener`, after the session listener).
- **Sharing failed open without a grantee listener:** `VaultGrantListQueryEvent::isGranteeAllowed()` accepted **any** user or team id typed in the share form when no `GRANT_LIST_QUERY` listener listed grantees, which allowed sharing with users of other tenants. It now fails closed (nobody can be granted) and the share page says sharing is not configured. `security.allow_unlisted_grantees: true` restores the old behaviour and is documented as unsafe.
- **Item existence leaked through 403:** new `security.deny_status` (`403` default, or `404`) applies to every permission denial on an item or folder, its grants and its field reveal, so an id never confirms that a resource exists.
- **Secret access was neither audited nor throttled:** new `VaultEvents::ITEM_REVEALED` (`VaultItemRevealedEvent`: user id, item id, item type, field names, channel `web` / `extension`; never a value), dispatched for each revealed field and for each credential returned by the browser extension `/logins` lookup. Both share **one** per-user budget, `security.secret_access_rate_limit` (default 60 requests per 60 s, cache-backed; 429 with `Retry-After`): a reveal and a `/logins` call each cost one unit, whatever the number of credentials `/logins` returns. The bucket is keyed by the user id, or by the user identifier for user objects without an id. Use a shared, persistent cache pool; the limit is best effort under concurrent requests.

### Changed

- **`vault_grants.created_by_id`** is `ON DELETE SET NULL` (was `CASCADE`): deleting the user who granted access no longer deletes the grants they made. `VaultGrant::getCreatedBy()` returns `?object`. Needs a schema migration (UPGRADING).

[1.15.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.15.2

## [1.15.1] - 2026-10-30

Security: Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1151).

### Security

- **Vault routes registered without the route loader (REQ-SF-004 rule 7):** on Symfony >= 7.3 `#[AsController]` and the `#[Route]` attributes autoconfigured the vault controllers with the `routing.controller` tag, so a host whose `config/routes.yaml` has the default `controllers: { resource: routing.controllers }` got the default `/tools/vault/...` pages and the browser extension API (`/api/vault/extension/login`, unauthenticated by design) even without importing the `nowo_vault` loader, bypassing the configured routes, `web_ui.enabled` and `browser_extension.enabled: false` (the default). The controllers are registered explicitly (`autoconfigure: false`, `controller.service_arguments`, plus `container.service_subscriber` for the `AbstractController` ones; no `#[AsController]`); the routes exist only where the host imports `type: nowo_vault`. Regression test with a kernel that only loads `routing.controllers`.

[1.15.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.15.1

## [1.15.0] - 2026-10-30

Vault: UiKit page contract for every page (scaffolds, confirm dialogs, no inline JS/CDN), fail-closed CSRF and role checks, item form 422 instead of 500 See [UPGRADING](UPGRADING.md#to-1150).

### Added

- **UiKit page contract (REQ-UI-003)** — every manage page extends a UiKit scaffold and gets its view model from the
  new `Nowo\VaultBundle\Ui\VaultPageFactory` (`VaultPages` resolves the layout): home → `page/dashboard`, items /
  shared / trash → `page/list`, item create / edit / view and share → `page/form`, runtime configuration →
  `page/settings`. Chrome (page header, flashes, filters frame, table, row actions, confirm dialogs, pagination, empty
  states) comes from UiKit and follows `nowo_ui_kit` (layouts, `css_framework`, `icon_set`, `row_actions_display`,
  `theme`, per-bundle exceptions `theme.bundles.NowoVaultBundle`). `docs/TEMPLATES.md` documents pages, data blocks and
  view models.
- **Canonical `web_ui` keys:** `web_ui.enabled` (false keeps only the browser-extension API), `web_ui.path_prefix`,
  `web_ui.layout_template` (default `null` = `nowo_ui_kit.layouts.admin`), `web_ui.templates.<page>` (unknown page ids
  rejected).
- **CSRF fail-closed (cross-bundle pattern P2):** `CsrfProtectionPass` refuses to compile the container when the manage
  UI is enabled without `security.csrf.token_manager` / the form CSRF extension, or with
  `framework.form.csrf_protection: false`. `symfony/security-csrf` is now a hard dependency.
- **Empty role lists (cross-bundle pattern P1):** the container refuses to compile when every role list is empty,
  `allow_unauthenticated` is false and no `access_checker` is set (an empty list already denied; this turns a silent
  lock-out into an error).
- `PageContractTest` (UiKit `PageContractTestCase`, strict missing-translation rule) renders every page in 21 states;
  `LegacyLayoutBridgeTest` locks the 1.x layout bridge; new translation keys in the 7 locales (`vault.action.open`,
  `vault.search.clear`, `vault.list.title`, `vault.confirm.trash_title`, `vault.confirm.trash_folder_title`, `vault.confirm.purge_title`,
  `vault.form.website_placeholder`).

### Changed

- **Destructive actions use the UiKit confirm `<dialog>`** (move an item / folder to the trash, delete a tag, purge,
  remove access): POST + the same CSRF token ids, no inline `confirm()` / `onclick` / `onsubmit` any more.
- **Password generator** is the UiKit `dialog` partial (`<dialog id="vault-password-generator">`); its endpoint and CSRF
  token are data attributes of the dialog instead of inline `<script>` globals (`window.VAULT_PASSWORD_URL` /
  `VAULT_PASSWORD_CSRF_TOKEN` removed). `vault.js` opens it when the host layout does not load the UiKit scripts.
- Pagination uses the UiKit `Pagination` view model (info line "Showing x–y of n", numbered pages); `?page=N` is
  unchanged.
- Share form: grantee type and permission choices are translated (`vault.grantee_type.*`, `vault.permission.*`) instead
  of the enum case names; grantee picker labels are printed as given.
- FormKit profile `vault` and the form types carry no framework classes (`nowo-ui-input` only); the host form theme
  styles the fields. The item form no longer forces `bootstrap_5_layout.html.twig`.
- Requires `nowo-tech/ui-kit-bundle` `^2.17.3` and `nowo-tech/form-kit-bundle` `^2.16`.

### Deprecated

- `route_prefix` → `web_ui.path_prefix`; `templates.layout` → `web_ui.layout_template`; `templates.<page>` →
  `web_ui.templates.<page>` (`templates.index` → `home`); `css_framework` → `nowo_ui_kit.css_framework` (copied there
  when set); Twig global `nowo_vault_css_framework`. A layout without the UiKit canonical blocks is wrapped in the
  deprecated bridge `vault/_legacy_layout.html.twig`. All removed in 2.0.

### Removed

- Template contract: `layout.html.twig` (Tabler CDN demo layout), `vault/base.html.twig`, `vault/_flashes.html.twig`,
  `vault/_page_header.html.twig`, `vault/_pagination.html.twig`, `vault/_item_row_actions.html.twig`,
  `vault/_grants_table.html.twig`, `vault/_share_form_fields.html.twig` (replaced by UiKit scaffolds / partials) and the
  `vault.pagination.*` translation keys. See UPGRADING.

### Fixed

- Submitting the item form with an empty title answered 500 (null on the string title); it now shows the NotBlank
  error with 422.

[1.15.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.15.0

## [1.14.0] - 2026-10-29

Vault: paginated lists, PSR-3 logging of sensitive operations, attribute routes, 100% coverage gates See [UPGRADING](UPGRADING.md#to-1140).

Paginated manage lists, logging of sensitive operations, injectable clock, attribute-declared routes and full
coverage of `src/`. See [UPGRADING](UPGRADING.md#unreleased).

### Added

- **Pagination (REQ-PERF-001).** The items, shared and trash pages show `nowo_vault.pagination.per_page` items
  (default **50**) with a `?page=N` pager (`vault/_pagination.html.twig`, translated in the 7 locales). Own / folder /
  tag / trash lists page in the database through the new optional `VaultItemPaginatorInterface` (implemented by the
  Doctrine repository with Doctrine's `Paginator`, tags fetch-joined); custom repositories without it are sliced in
  PHP. `VaultItemLister::list()` gains optional `page` / `perPage` arguments (null = full list, as before).
- **`browser_extension.max_login_items`** (default 1000): upper bound of login items decrypted per `/logins`
  lookup; a warning is logged when it truncates the scan.
- **Logging (REQ-OBS-001).** Optional `Psr\Log\LoggerInterface` in the extension auth service, grant service, trash
  service, runtime config writer, login resolver and both commands: rejected / successful extension logins, token
  revocation, grant changes, purges, runtime config changes (keys only), re-encryption runs. No credentials, keys,
  payloads or titles are logged; policy in `docs/SECURITY.md#logging`.
- **Clock (REQ-DI-001).** Extension token expiry and last-use touch read an injected `Psr\Clock\ClockInterface`
  (system clock fallback); `VaultExtensionToken::isExpired()` / `touch()` accept an optional `$now`.
- Demo Makefile: `restart`, `setup`, `test-coverage`, `verify` and a standalone `update-deps`; root `update-deps`
  fallback for standalone checkouts (REQ-MAKE-003 / REQ-MAKE-008).

### Changed

- **Routes are declared with `#[Route]` attributes on the controllers (REQ-SF-004)** (`#[AsController]` too). The
  `nowo_vault` loader now reads those attributes and still applies `routes.*` / `browser_extension.routes.*` paths,
  names and `route_prefix`; the generated collection (names, paths, methods, requirements, controllers) is unchanged
  (locked by `VaultRouteLoaderAttributesTest`). Routes are added in controller declaration order.
- New runtime requirements: `psr/clock`, `psr/log`, `symfony/clock` (already installed transitively by Symfony).
- PHP coverage gate measures **all of `src/`** (only `src/Resources` excluded): the controller, repository, form,
  Doctrine, routing, command and DI exclusions were removed and the missing tests added; CI now fails below 100%
  (`scripts/check-coverage.php`). Vitest measures every TypeScript source (99.69% lines) with thresholds in
  `vitest.config.ts`, run by a new CI job (REQ-TEST-003).
- `make help` lists targets in the canonical block order (REQ-MAKE-001); every public class and method in `src/`
  has a PHPDoc description (REQ-CS-001).

### Documentation

- USAGE: lists and pagination; Twig overrides with the freeze rule, upgrade-safe depths, procedure, recovery and the
  table of every overridable `<subpath>` (REQ-TWIG-001). CONFIGURATION: routes, pagination, clock and logger.
  README: numeric PHP / TS coverage (REQ-TEST-007).

[1.14.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.14.0

## [1.13.0] - 2026-10-28

Vault: no raw translation keys in item forms, password widget copy or generator labels. See [UPGRADING](UPGRADING.md#to-1130).

### Fixed

- **Raw translation keys in the item forms.** FormKit's convention keys (`vault_item_form.<field>.help` /
  `.placeholder`, `vault_share.grantee_id.placeholder`) were printed as is; the `vault` FormKit profile now sets
  `convention_requires_translation: true`, so only translated conventions are applied (requires FormKit ^2.13).
- **Password widget copy.** With PasswordStrengthBundle >= 2.8 the requirements list and generator button use the
  `NowoPasswordStrengthBundle` catalogue (`ui_translation_domain`) instead of showing `requirement.*` /
  `generator.button` keys; the strength constraint is built with named arguments there.
- **Password generator labels.** The modal forces `form_div_layout.html.twig` with `only`: a host Bootstrap form theme
  rendered the checkbox / radio labels empty.

### Changed

- `nowo-tech/form-kit-bundle` constraint raised to `^2.13`; `composer-runtime-api ^2.0` required (installed-version
  check). Demo: PasswordStrengthBundle `^2.10`.

[1.13.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.13.0

## [1.12.0] - 2026-10-28

Vault: the browser extension API applies the Vault role gate and re-validates tokens on every request. See [UPGRADING](UPGRADING.md#to-1120).

### Security

- **Browser extension API honours the Vault role gate.** The Bearer API never called `VaultAccessCheckerInterface`, so a
  user without `security.access_roles` still obtained a token and their login passwords. Login and every Bearer request
  now require `canAccess()` for the token's user; `ConfigurableVaultAccessChecker` evaluates an explicit user with
  `Security::isGrantedForUser()` (role hierarchy included) because extension requests have no security token.
- **Extension tokens are re-validated on every request.** The firewall user checker (disabled, locked, expired
  accounts) also runs when a token is used, not only at login. A user who fails it or lost the Vault roles gets 401 and
  all their tokens are revoked. New `VaultBrowserExtensionAuthService::revokeAllFor($user)` for hosts that want to
  revoke on password change.

[1.12.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.12.0

## [1.11.1] - 2026-10-28

Security: trashed items and folders answer 404 except for restore, purge and grant revocation. See [UPGRADING](UPGRADING.md#to-1111).

### Security

- **Trashed items and folders answer 404.** A trashed item or folder stayed viewable, editable and shareable by id
  (only the lists hid it). Only restore, purge and grant revocation act on trashed resources now.

[1.11.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.11.1

## [1.11.0] - 2026-10-28

Security: browser-extension autofill can no longer be triggered by the page; Write grantees cannot escalate through folders. See [UPGRADING](UPGRADING.md#to-1110).

### Security

- **Browser extension: the page could trigger the autofill and read the password (High).** The autofill panel was
  plain page DOM with a fixed id and its buttons did not check `event.isTrusted`, and the content script received
  every matching password before any click: page JavaScript (an XSS, a subdomain owner, or a network attacker serving
  the plain-HTTP twin of a stored https site) could `click()` the panel and read the filled field. Now:
  - the panel lives in a closed shadow root, clicks are honoured only when trusted, and the content script receives
    id / title / username only; the password is released by the background after the trusted click;
  - the background takes the page host and scheme from the sender tab (not from the message) and only answers its
    own extension;
  - on `http:` pages only logins whose website is stored explicitly as `http://…` are offered (new `scheme` query
    parameter of `/api/vault/extension/logins`; older extensions keep the previous behaviour);
  - DOM rescans are debounced; the options page requires an `https://` vault URL (http only for localhost).
  **Reinstall / update the extension** from `extension/chrome` or `extension/firefox`.
- **Write grantee could escalate to Admin (Medium).** The edit form saved the folder the editor chose from their own
  folders, and folder grants applied to every item in the folder: a grantee with Write could move the owner's item
  into a folder they own and share that folder as Admin (re-share, delete, purge). Folder grants now cover only items
  created by the folder's creator, and grantees can no longer change an item's folder or tags (which also stopped
  non-owner edits from moving the item to "no folder" and replacing the owner's tags). Sharing with yourself is
  refused.
- `security.allow_unauthenticated: true` refuses to compile in the `prod` environment.
- The extension API no longer sends `Access-Control-Allow-Credentials` (Bearer tokens, no cookies).

[1.11.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.11.0

## [1.10.0] - 2026-10-28

Performance: items page folder counts in one query and item tags fetch-joined. See [UPGRADING](UPGRADING.md#to-1100).

### Performance

- **Items page without per-folder and per-item queries.** The folder list ran one `COUNT` per folder and the item
  table loaded `item.tags` lazily per row. Folder counts now come from one `GROUP BY` (new optional
  `VaultItemFolderCounterInterface`, implemented by `DoctrineOrmVaultItemRepository`; custom item repositories without
  it keep the per-folder count), and `findByCreator()` / `findByCreatorAndItemType()` / `findByCreatorAndFolder()`
  fetch-join the tags.

[1.10.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.10.0

## [1.9.0] - 2026-10-26

Forms in the bundle views are Symfony Form Types rendered with form_start / child loop / form_end, no raw form controls (REQ-TWIG-005). See [UPGRADING](UPGRADING.md#to-190).

### Changed

- Manage views render every control through Symfony Form Types (REQ-TWIG-005): trash / restore / purge / revoke grant / trash folder / delete tag use `VaultActionType` (flat `_token`, same token ids, still validated by `VaultCsrfTrait` so header / JSON clients keep working), the item search uses `VaultItemSearchType` (GET, same query names), folder creation `VaultFolderCreateType`, the runtime configuration page `VaultRuntimeConfigType`, the password generator modal the browser-only `VaultPasswordGeneratorType`, and the identity-card attachment is an unmapped `attachment` child of `VaultItemFormType` (still posted as `attachment`; the item form is now multipart, so the upload reaches the controller). Template overrides must render the new form variables (see UPGRADING).

### Added

- `make check-no-raw-html-form` (REQ-TWIG-005) fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle and demo Twig; it runs in `release-check`.

### Changed

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `validate-translations`, `coverage-check`, demo release-check, `test-ts`). `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.
- Internal: applied the pending Rector suggestions (`VaultMetadataListener` private static helper made non-static, `readonly` on the final `VaultAccessGuard`, early return in `VaultTagService` name normalisation, redundant `@var` dropped in `VaultItemFormData`); no behaviour change. `make rector-dry` is clean again.

[1.9.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.9.0

## [1.8.2] - 2026-10-24

Bundle views render form bodies through the child loop (REQ-TWIG-003). See [UPGRADING](UPGRADING.md#to-182).

### Changed

- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Views: `vault/share.html.twig` / `_share_form_fields.html.twig` render the share form through the child loop
  (`{% for child in form %}` + `not child.rendered` + `form_row(child)`, grid column per child) and
  `vault/item_form.html.twig` keeps its per-item-type layout (rule 3) and ends with the same loop instead of a
  hand-placed `_token` widget, per REQ-TWIG-003. The item loop skips the type-specific fields that the current item
  type does not show, so the rendered fields are unchanged.

### Documentation

- Documentation: wave-1 REQ compliance — README Coverage badge, Symfony badge lists 8.1, stars badge uses the package name, documentation list order (extras under Additional documentation), canonical issue templates, CONTRIBUTING Code of Conduct link and Cursor Agent queue settings (REQ-IDE-006), SDD functional scope / non-goals and Engram relationship, demo `.env.test` / `.gitignore` cleanup, tables of contents on long docs, Spec Kit inventory refresh (123/123).

[1.8.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.8.2

## [1.8.1] - 2026-10-18

Allows nowo-tech/ui-kit-bundle 2.x (^1.4 || ^2.2) so hosts can move to UiKit 2.2. See [UPGRADING](UPGRADING.md#to-181).

### Changed

- Allows nowo-tech/ui-kit-bundle 2.x (`^1.4 || ^2.2`); the dev lock and the demo lock use 2.2.0. Templates are unchanged (compatibility release). With UiKit 2.x `nowo-ui.css` ships in `@layer nowo-ui`, so unlayered host/framework CSS wins equal-specificity conflicts with kit rules in the vault pages; to keep the 1.x cascade, override the `vault/base.html.twig` stylesheets block and link `css/nowo-ui.unlayered.css` (UiKit 2.x only).

[1.8.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.8.1

## [1.8.0] - 2026-10-13

### Security

- Browser-extension login now runs the **firewall** user checker by default, so disabled/locked users really are refused Bearer tokens (the 1.6.0 fix did not hold by default). The default used the global `UserCheckerInterface` alias, which is the no-op `security.user_checker` for entity users and is not registered at all once any firewall sets a custom `user_checker` (no checker ran). It now resolves `security.user_checker.<firewall>` (the firewall's checker or chain, incl. UserKit's account-status checker) in a compiler pass.
- New option `browser_extension.firewall` (default null = the firewall named `main`, else the first firewall with security enabled) selects whose user checker the extension login runs. `browser_extension.user_checker` still wins when set; an unknown firewall fails at compile time.

### Fixed

- `browser_extension.user_provider` / `browser_extension.user_checker` were silently ignored: the bundle's resource scan re-registered `DefaultVaultBrowserExtensionAuthenticator` (and `VaultBrowserExtensionResponseFactory`) as plain autowired services after the extension had configured them. Explicit definitions are now applied after the scan, so the configured services are used and the response factory receives `kernel.environment` (CORS wildcard `*` honoured in non-`prod` environments as documented, still rejected in `prod`).

[1.8.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-13

### Security

- Browser extension login: new per-IP rate-limit bucket `browser_extension.login_rate_limit.max_attempts_per_ip` (default `20`, `0` disables) blocks credential stuffing across many usernames from one IP. The existing IP + username bucket (`max_attempts`) is unchanged; a successful login clears only that bucket.

### Fixed

- `nowo:vault:reencrypt` / `VaultPayloadReencryptionService` can be re-run after an interruption: items that already decrypt with the new key are skipped (reported as skipped, `VaultPayloadReencryptionResult::$skippedItems`) instead of aborting on the first migrated item. Batches with only skipped items are not saved.

### Changed

- Docs: `password_field.generator_mode` and `password_field.use_password_toggle` are documented in `docs/CONFIGURATION.md`.
- Development: `vitest` and `@vitest/coverage-v8` upgraded from `^3.0.0` to `^4.1.11` (test tooling only; shipped assets unchanged). The config needed no changes; vitest 4's AST-based V8 coverage reports slightly different percentages for the same files.

[1.7.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-10-13

### Security

- Runtime config screen: a YAML/env `nowo_vault.encryption_key` now counts as an existing key, so "Generate" or a pasted key can no longer silently switch the master key and make every item undecryptable. Rotate with `nowo:vault:reencrypt --persist-new-key`.
- Pasted encryption keys are validated (base64, 32-byte libsodium key) before saving; `VaultRuntimeConfigWriter::update()` rejects invalid keys.
- The master key is no longer stored in `vault_settings` unless `nowo-tech/doctrine-encrypt-bundle` is installed (encryption at rest). The runtime config screen shows a warning and `nowo:vault:reencrypt --persist-new-key` exits before re-encrypting.
- Browser extension login runs the Symfony user checker (`checkPreAuth` / `checkPostAuth`): disabled or locked users no longer receive Bearer tokens. New option `browser_extension.user_checker` (defaults to the `UserCheckerInterface` alias).
- Browser extension CORS: when `cors_allowed_origins` is set, only the listed origins are allowed; the "any `chrome-extension://` / `moz-extension://`" fallback now applies only when the list is empty.

[1.6.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.6.0

## [1.5.1] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.
- Repository: `.twig-cs-fixer.cache` is now git-ignored.

### Dependencies

- `nowo-tech/form-kit-bundle` 2.6.1 → 2.7.1 (development lock; #77).
- `nowo-tech/ui-kit-bundle` 1.9.2 → 1.11.0 (development lock; #78).
- `@types/chrome` 0.3.4 → 0.3.5 (frontend tooling; #79).
- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #80).
- `nowo-tech/doctrine-encrypt-bundle` 2.4.3 → 2.5.0 (development lock; #81).
- `vite` 8.3.2 → 8.3.4 (frontend tooling; #82).
- `jsdom` 30.1.1 → 30.1.2 (frontend tooling; #83).

[1.5.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-10-09

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); `doctrine/orm` requirement raised to `^3.7` (was `^2.15 || ^3.0`).

### Dependencies

- `igor-php/igor-php` require-dev constraint bumped to `^0.10.0`; Dependabot bumps for `doctrine/orm`, `nowo-tech/form-kit-bundle`, `nowo-tech/ui-kit-bundle`, PHPStan, PHP CS Fixer and npm (`vite` 8.3.2, `jsdom` 30.1.1, `@types/chrome` 0.3.4).
- Lock refreshed: `doctrine/orm` 3.7.4, `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.2, Symfony 7.4.20, PHPStan 2.3.1, Rector 2.7.0.
- Demo `symfony8`: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, Twig 3.30.0, latest nowo-tech form widget bundles.

[1.5.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.5.0

## [1.4.8] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `VaultRuntimeConfigProvider` no longer memoizes the DB-merged runtime config (including `encryption_key`) in the service; it reads the shared cache pool on every call, so a config change or key rotation from another worker or the CLI is seen on the next request (W-01).
- **FrankenPHP worker mode:** `VaultAccessGuard` no longer caches `ITEM_READ_ONLY_RESOLVE` decisions across requests (stale authorization, unbounded memory) (W-02).
- **FrankenPHP worker mode:** Doctrine repositories resolve the EntityManager from `ManagerRegistry` per call and reset it when a previous flush closed it; `DoctrineOrmVaultSettingsRepository::findByScope()` always refreshes the settings row from the database (W-03).
- **Routing:** `VaultRouteLoader` can be loaded more than once in the same process (W-04).
- **Doctrine:** entities no longer declare `repositoryClass` pointing to the standalone `DoctrineOrm*Repository` services, so `EntityManager::getRepository(VaultItem::class)` (and the other vault entities) returns a default `EntityRepository` instead of throwing a `TypeError`.
- **Doctrine ORM 2:** `VaultMetadataListener` also remaps array association mappings, so the user association target and the item/tag join table name are applied on ORM 2 as well (user associations now use `UserInterface` as the placeholder target until remapped to `user_class`).
- Fixed PHPStan findings (0 errors at level 8); `src/Entity` and `src/Doctrine` are analysed again.

[1.4.8]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.4.8

## [1.4.7] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.4.7]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.4.7

## [1.4.6] - 2026-08-20

### Security

- **Flex recipe:** `when@prod` forces `allow_unauthenticated: false`; document extension `PUBLIC_ACCESS` path; REQ-SEC-004 re-audit **Pass (good)**. Prefer **`^1.4.6`**.

## [1.4.5] - 2026-08-19

### Security

- **Dependencies:** bump `paragonie/sodium_compat` to **v2.5.2** (PKSA-32g2-byr9-drtw / Ed25519 validation) so `composer audit --locked` passes in CI.

## [1.4.4] - 2026-08-19

### Security

- **Flex recipe:** ship `security_nowo_vault.yaml` access rules (REQ-SEC-004). Prefer **`^1.4.4`** over `v1.4.3`.

## [1.4.3] - 2026-08-19

### Security

- **Flex recipe:** `security.access_control` for `^/tools/vault` (REQ-SEC-004).

## [1.4.2] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.4.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.4.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.4.1

## [1.4.0] - 2026-08-04

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `vault` (`#[FormKitConfig]`), including full `VaultItemFormType` field helpers (`withBuilder` / `add*Field` / `addTypedField` for TagInput, PasswordStrength, and folder EntityType). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected. Test kernel registers `NowoUiKitBundle`, `NowoFormKitBundle`, and `TwigExtraBundle`.

### Added
- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **REQ-UI-001-kit:** Require **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Vault base loads kit CSS; flashes and row actions compose kit macros. `VaultExtension` seeds `nowo_ui_kit` from root `css_framework` (raw config — avoids required `encryption_key` during prepend) when the host has not configured UiKit. Demo registers UiKit and ships `nowo_ui_kit.yaml` (`tabler` / `tabler-icons`).

[1.4.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.4.0

## [1.3.0] - 2026-08-03

### Added

- **REQ-UI-002:** `security.allow_unauthenticated` (default `false`) with `AllowAllVaultAccessChecker`, compile-time SecurityBundle guard, and controller early-return when the flag is enabled.
- Controllers inject `%nowo_vault.security.allow_unauthenticated%` (manage + runtime config); soft gate replaces hard `#[IsGranted('IS_AUTHENTICATED')]`.

### Changed

- Docs: CONFIGURATION / SECURITY; Flex recipe comments for `access_roles` / `allow_unauthenticated`.
- Dev deps (Dependabot): php-cs-fixer 3.95.18, rector 2.5.9→2.6.0, phpstan group, `phpstan-frankenphp` 1.0.2→1.0.3, vite 8.2.0, jsdom 30.0.1, `actions/stale` v11, doctrine-encrypt-bundle, Symfony 7.4.15 lock refresh.

### Compatibility

- PHP `>=8.2 <8.6`; Symfony `^7.4 || ^8.0`.
- Manage UI with default security settings requires **SecurityBundle** (or set `allow_unauthenticated: true` for trusted local demos only).

## [1.2.0] - 2026-07-30

### Added

- **REQ-UI-001 `css_framework`** — root enum (`tabler` default; also `bootstrap5` / `bootstrap` / `bootstrap4` / `tailwind` / `foundation` / `custom` / `none`). Parameter `nowo_vault.css_framework` and Twig global **`nowo_vault_css_framework`**. Demo layout CDN gated for Tabler/Bootstrap-compatible values.
- Intermediate shell **`vault/base.html.twig`**: manage pages extend it; stacks `vault.css` / `vault.js` with **`{{ parent() }}`** so host layouts keep their assets.

### Changed

- Manage templates (`home`, `items`, `item_form`, `trash`, `shared`, `share`, `runtime_config`) extend **`vault/base.html.twig`** instead of `layout` directly.
- Default **`layout.html.twig`**: `nowo-ui-css-*` / `data-css-framework` on `<html>`; package assets moved into `base` (demo chrome remains Tabler CDN when applicable).

### Documentation

- **[CONFIGURATION](CONFIGURATION.md)** / **[USAGE](USAGE.md):** look-and-feel, host layout, freeze rule for Twig overrides.
- **[UPGRADING](UPGRADING.md)** section **1.2.0**.

## [1.1.5] - 2026-07-29

### Added

- `make check-open-prs`, `coverage-check`, `demo-smoke`, `down-dev` (REQ-MAKE-002 / 007).
- `SYMFONY_DEPRECATIONS_HELPER=max[direct]=0` (REQ-SF-005).
- FrankenPHP Friendly banner (`docs/images/frankenphp-friendly.png`) — REQ-DOCS-017.
- `docs/SECURITY.md` residuals + SEC-004 Pass (conditional) row in 12.4.1.
- Unit tests closing measured PHP coverage gaps for the 100% gate.

### Changed

- Demo Symfony 8 image `dunglas/frankenphp:1-php8.5-bookworm` (REQ-DEMO-010).
- Packagist homepage + GitHub About topics (REQ-DOCS-018).
- CI Symfony matrix drops 7.0 (align with `^7.4`); coverage job on 8.2/7.4.
- `release-check` no longer runs `cs-fix`; includes `check-open-prs` + `coverage-check`.
- PHPStan: `ignoreErrors: []`; Entity/Doctrine paths excluded; FrankenPHP rulesets (REQ-CS-005/006).

## [1.1.4] - 2026-07-22

### Added

- **Demo** — `FRANKENPHP_MODE` (`classic` | `worker`) in `.env` / Compose to switch FrankenPHP Caddyfile without rebuilding the image; documented in [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)

### Changed

- **CS Fixer** — `fully_qualified_strict_types.import_symbols` enabled; source and tests use imported symbols instead of leading-backslash FQCNs
- **Dev / CI deps** — `nowo-tech/doctrine-encrypt-bundle` ^2.3, Vite 8.1.5, Rector 2.5.7, PHP-CS-Fixer 3.95.15, `actions/checkout@v7`

## [1.1.3] - 2026-07-16

### Added

- **Code of Conduct** — Contributor Covenant (`CODE_OF_CONDUCT.md`); linked from README and [CONTRIBUTING](CONTRIBUTING.md)
- **Git hooks (REQ-GIT-001)** — `.githooks/commit-msg` rejects Cursor `Co-authored-by` trailers; `make setup-hooks`, `make check-no-cursor-coauthor`, `make strip-cursor-coauthor-from-history`
- **Docs** — [GITHUB_CI.md](GITHUB_CI.md) for CI history checks and contributor setup

### Changed

- **CI** — enforces no Cursor co-author trailers on push/PR history
- **release-check** — runs `check-no-cursor-coauthor` before the rest of the QA pipeline

## [1.1.2] - 2026-07-13

### Fixed

- **CI** — PHP 8.4/8.5 × Symfony 8.0/8.1 matrix: test kernel compatible with Doctrine Bundle 3 (`entity_managers.default` config, no deprecated `auto_generate_proxy_classes`, native lazy objects on PHP 8.4+)
- **CI** — Doctrine lazy proxies on PHP 8.2–8.3 via `symfony/var-exporter` in `require-dev`
- **Tests** — E2E and integration suites call `ensureKernelShutdown()` in `tearDown()` to avoid double-boot failures

### Changed

- **Dev** — `nowo-tech/doctrine-encrypt-bundle` bumped to `^2.2` in `require-dev`
- **CI** — workflow installs `symfony/var-exporter`, `browser-kit`, `asset`, and `dom-crawler` per Symfony matrix version

## [1.1.1] - 2026-07-08

### Added

- **GitHub Spec Kit baseline** — `specs/001-baseline/` (product spec + 100% `src/` code inventory), `.specify/` scaffolding, Cursor Agent `speckit-*` skills
- **Docs** — [GitHub Spec Kit](SPEC-KIT.md); expanded [Spec-driven development](SPEC-DRIVEN-DEVELOPMENT.md) (three-layer model, contributor workflow, US-06)

### Changed

- Demo Symfony 8: `doctrine-encrypt-bundle` and `tag-input-bundle` installed from Packagist (`^2.0`, `^1.0`) instead of sibling path repositories; removed extra Docker volume mounts for those bundles

## [1.1.0] - 2026-07-05

### Added

- **Browser extension API** — Bearer-token auth (`/api/vault/extension/login`, `/me`, `/logins`, `/logout`); optional Chrome/Firefox extension under `extension/` with build/sync scripts
- **Item tags** — assign, filter, and search by tag; `{table_prefix}_tags` and `{table_prefix}_item_tag` tables; optional `nowo-tech/tag-input-bundle` integration
- **Database-backed runtime configuration** — optional `config_storage.enabled` with `{table_prefix}_settings`, admin UI, cache invalidation via `VaultRuntimeConfigWriter`; optional `nowo-tech/doctrine-encrypt-bundle` for encrypted key at rest
- **Encryption key rotation** — console command `nowo:vault:reencrypt` (`--old-key`, `--new-key`, `--dry-run`, `--persist-new-key`, `--force`); demo Make targets and `scripts/vault-key-rotation-demo.sh`
- **CSRF protection** — `VaultCsrfTrait` on all manage UI state-changing POST actions (token via `_token`, `X-CSRF-Token`, or JSON body)
- **Extension login rate limiting** — cache-backed `browser_extension.login_rate_limit` (HTTP 429)
- **Token maintenance** — console command `nowo:vault:extension-tokens:purge` for expired Bearer tokens
- **Translations** — German, French, Italian, Dutch, and Portuguese (`NowoVaultBundle.*.yaml`)
- **Events** — `VaultBrowserExtensionAuthEvent` for custom extension authentication
- **Docs** — [Browser extension](BROWSER-EXTENSION.md), [Encryption key rotation](ENCRYPTION-KEY-ROTATION.md); expanded CONFIGURATION, SECURITY, INSTALLATION, USAGE
- **Tests** — E2E for manage CSRF and extension API; unit tests for CORS, auth service, rate limiter, reencrypt, purge command, runtime config

### Changed

- Manage UI templates and `vault.js` include CSRF tokens on POST forms and fetch calls
- Item list row actions extracted to `_item_row_actions.html.twig` (replaces `_item_access.html.twig`)
- `VaultSharedItemResolver` and item repository optimized for extension login resolution
- Extension token `last_used_at` updates debounced (5 minutes)
- Demo Symfony 8: FrankenPHP, browser-extension fixtures, rotation demo commands, integration tests

### Security

- Manage routes require valid CSRF token on mutating POST requests
- Extension login protected by configurable rate limit; session CSRF does not apply to Bearer API (documented)

## [1.0.0] - 2026-07-04

First stable release of **VaultBundle** — password and secrets vault for Symfony.

### Added

- Password and secrets vault: items, folders, grants, trash, password generator
- Item types: login, secure note, credit card, contact, identity documents, document attachments
- Sharing UI for items and folders (user/team grants with read, write, admin)
- `VaultTeamMembershipResolverInterface` for team membership
- Shared items list via `VaultGrant`
- Search by title in vault index
- Read-only mode via `VaultEvents::ITEM_READ_ONLY_RESOLVE`
- Document file attachments (encrypted in payload)
- Server-side libsodium payload encryption
- Symfony events for list queries, access checks, and grant picker (`VaultGrantListQueryEvent`)
- Symfony Flex recipe `.symfony/recipes/nowo-tech/vault-bundle/1.0.0`
- Demo Symfony 8 + FrankenPHP on port **8023**
- Docs: INSTALLATION, CONFIGURATION, USAGE, CONTRIBUTING, CHANGELOG, UPGRADING, RELEASE, SECURITY, ENGRAM, DEMO-FRANKENPHP, SPEC-DRIVEN-DEVELOPMENT, Access control examples

### Changed

- Replaced Yopass share/E2E scaffolding with vault domain model
- Documentation rewritten for vault use cases

[Unreleased]: https://github.com/nowo-tech/VaultBundle/compare/v1.16.0...HEAD
[1.3.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.3.0
[1.2.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.2.0
[1.1.5]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.5
[1.1.4]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.4
[1.1.3]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
