# Feature Specification: VaultBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-07  
**Status**: Active  
**Input**: Backfill GitHub Spec Kit baseline documenting 100% of production code in `src/`.

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md)  
**Code inventory (traceability)**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/vault-bundle`  
**Configuration root**: `vault`


Symfony bundle for a **password and secrets vault**: encrypted items (logins, notes, cards, contacts, documents), folders, sharing, trash, password generator, browser-extension autofill API, and runtime config UI.

---

## User Scenarios & Testing

### US-01 — Store and organize vault items (Priority: P1)

As a user, I CRUD typed items in folders with tags and soft-delete to trash.

**Acceptance**: `VaultItemCreator/Updater/Lister`, `VaultFolderService`, `VaultTrashService`, manage UI.

### US-02 — Share items and folders (Priority: P1)

As an owner, I grant read/write permissions to users or teams.

**Acceptance**: `VaultGrantService`, grants table UI, `VaultSharedItemResolver`.

### US-03 — Encrypt payloads at rest (Priority: P1)

As an integrator, I configure Sodium or runtime-key encryption for item payloads.

**Acceptance**: `VaultPayloadCryptographerInterface` implementations, `ReencryptVaultPayloadsCommand`.

### US-04 — Browser extension autofill (Priority: P2)

As a user with the extension, I authenticate and receive matching logins for a domain.

**Acceptance**: `VaultBrowserExtensionController`, auth service, domain matcher, rate limiter.

### US-05 — Extend ACL via events (Priority: P2)

As an integrator, I hook list/access events to enforce custom team rules.

**Acceptance**: `VaultItemListQueryEvent`, `VaultItemAccessCheckEvent`, `VaultAccessGuard`.

---

## Requirements

### Bundle, config & routing

- **FR-BUNDLE-001 / FR-CFG-001 / FR-CFG-002 / FR-CFG-004**: Static and runtime configuration trees; extension loads services and route loader.
- **FR-ROUTE-001**: `VaultRouteLoader` exposes configurable manage routes.
- **FR-DI-001 / FR-DI-002**: Service wiring and Twig paths pass.

### Persistence & encryption

- **FR-ENTITY-001 / FR-REPO-001 / FR-REPO-002**: Vault entities (items, folders, grants, tags, settings, extension tokens) and ORM repositories.
- **FR-CRYPT-001 / FR-CRYPT-003**: Payload encryption interfaces, Sodium/runtime implementations, bulk re-encryption service.
- **FR-DB-001**: Doctrine metadata listener and database driver helper.

### Domain services

- **FR-ITEM-001–003**: Item create/update/list with event hooks.
- **FR-FOLDER-001 / FR-TRASH-001 / FR-TAG-001**: Folder tree, trash lifecycle, tag assignment.
- **FR-SHARE-001–003**: Grant CRUD, grant list resolver, shared-with-me view.
- **FR-PWD-001 / FR-PWD-002**: Server password generator + frontend client.
- **FR-PLUG-001**: Optional integrations (DoctrineEncrypt, password strength, tag input).

### Browser extension

- **FR-EXT-001–005**: Authenticator contract, default implementation, login domain matching, rate limiting, JSON response factory.

### Security & UI

- **FR-SEC-001 / FR-SEC-006**: Access checker and team membership resolver contracts + configurable defaults.
- **FR-FORM-001**: Item and share form types with custom form theme.
- **FR-VIEW-005 / FR-VIEW-009**: Manage UI templates and password generator partial.
- **FR-UI-010–012**: Vault frontend assets (Stimulus/controllers, styles).
- **FR-CLI-004**: Purge extension tokens, reencrypt payloads.

### Runtime configuration, domain events and supporting types

- **FR-CFG-003**: Runtime (database-stored) configuration: `VaultRuntimeConfigProvider` / `Schema` / `Writer`, `VaultRuntimeConfigResolver` and the runtime-config controller.
- **FR-EVT-001**: Domain events (`VaultEvents`, access-check, list-query / list-result, read-only, browser-extension auth events) for host extension points.
- **FR-DTO-001**: Form / transfer objects (item and share form data, grantee choice, password generator options, extension login match).
- **FR-MDL-001**: Domain enums (`GranteeType`, `VaultItemType`, `VaultPermission`, `VaultResourceType`).
- **FR-UTIL-001**: Support utilities (`UserIdResolver`, `Uuid` value object).
- **FR-SEC-003**: CSRF validation on every state-changing manage action (`VaultCsrfTrait`).
- **FR-UI-001**: `VaultManageController` serves the manage Web UI (items, folders, shares, trash).
- **FR-VIEW-010** (2.3, lazy dialogs): the extension prepends `nowo_ui_kit.theme.bundles.NowoVaultBundle.partials.table` = `VaultPages::TABLE_PARTIAL` (`ui/_table.html.twig`, extends the host / kit `table` partial with `confirm_shared` on; a host value wins), so every vault table (items, shared, trash, scope items, share grants) prints ONE shared confirm dialog instead of one per row; the folder and tag chips of the items list share one more (`vault-chips-confirm`). Each row keeps its own hidden POST form, target and CSRF token and no secret value reaches the page. Needs ui-kit ^2.68.
- **FR-UI-013** (REQ-ASSETS-005): `vault.ts` prints one `📦 [vault] script loaded, build: <date> UTC` console line through `logger.ts` (readable build label); `<html data-nowo-console="quiet">` or `globalThis.NOWO_CONSOLE = 'quiet'` silences it, and the level methods are no-ops unless debug is on.
- **FR-VIEW-001**: `layout.html.twig` and `vault/base.html.twig` page shell (extends the controller layout, UiKit, REQ-UI-001).
- **FR-VIEW-002**: `VaultExtension` exposes the manage Web UI globals to Twig.
- **FR-BUILD-001**: Vite builds `Resources/public/vault.js` / `vault.css` from `Resources/assets/src/` (documented as outputs, not orphan files).
- **FR-WORKER-001**: FrankenPHP worker safety without kernel reset: repositories resolve the EntityManager per call and reopen a closed one (`ResolvesEntityManagerTrait`); no per-request state in shared services.

---

### Scope vaults, grants, catalogue, reveal, health and personal data (1.16-1.19)

- **FR-SCOPE-001**: Scope vaults (`scopes.enabled`): items, folders and tags owned by a host scope key (`VaultOwner`), membership from the scope host port memoized per request, scope pages and repositories, dry downgrade check.
- **FR-GRANT-001**: Temporary grants with expiry enforced on every access, purge command and `GRANT_*` events with ids only; `GRANT_POLICY_CHECK` veto with a reason code.
- **FR-CAT-001**: Metadata-only catalogue of a scope (no folder, creator or payload), `CATALOGUE_ACCESS_CHECK`, GET search form; 2.5.0 creator filter (`VaultCatalogueQuery::$ownerId`, exact id, capped at 128 characters, resolved through the user directory with the relation mapping so a malformed id never reaches the column) with *Everyone* / *Created by me* chips.
- **FR-REVEAL-001**: Field reveal, copy and attachment download over POST + CSRF, per-user rate limits, `no-store` headers on every bundle response, values hidden from dumps, `ITEM_FIELD_REVEAL_CHECK` / `REVEAL_THROTTLED` events.
- **FR-HEALTH-001**: On-demand password health per owner (weak, reused, old, optional breach hook), password policy per owner (optionally enforced on save), size and interval limits, events with counts only.
- **FR-GDPR-001**: GDPR metadata export and erasure of one user by id; decrypted personal export behind a host re-authentication proof checked on the bundle clock, with a per-user lock. 2.5.0: one export section per erasure step (`ERASURE_SECTIONS`, adds `grants_on_personal` and `scope_folders_created`); inside an open transaction the erasure joins it (no own transaction or savepoint).
- **FR-GDPR-002** (2.5.0, Beacon spec 185 FR-023): UserKit bridges `Bridge\UserKit\VaultUserKitEraser` (key `vault`, priority 180) and `VaultUserKitExportProvider` (section `vault`, priority -50), registered when nowo-tech/user-kit-bundle is installed and `personal_data.user_kit` is true; the user id is the string form of `getId()`; the erasure joins UserKit's transaction.
- **FR-LIMIT-001** (2.5.0, Beacon spec 185 FR-020): `ITEM_CREATE_CHECK` (`VaultItemCreateCheckEvent`, ids and type only, `deny()` with a short code) dispatched by `VaultItemCreateGate` before every item creation (pages and command ports); lazy `itemCount()` reading the owner's items twice with `SELECT … FOR UPDATE` inside the write unit; refusal as `VaultItemCreateDeniedException` (form again with 422 on the pages).
- **FR-EXT-006** (2.5.0, Beacon spec 185 FR-022): per-user token port `VaultExtensionTokenCommandsInterface` / `VaultExtensionTokenCommands` (list, revoke, revoke all) with `VaultExtensionTokenView` (id and dates only) and `EXTENSION_TOKEN_REVOKED` (`VaultExtensionTokenRevokedEvent`) after the commit; exact owner check, another user's id answers like an unknown one; works with the token entity unmapped.
- **FR-USER-001**: `user_mapping: relation | id` (1.17), accepted user classes, strict ids compared byte for byte (binary NO PAD collation since 1.19.3), refusal of users of other classes.
- **FR-PORT-001**: Framework-free host ports and VOs (1.19, REQ-ARCH-001 rule 3): access policy, scope access, team membership, re-authentication, health report, user directory, `VaultUserRef`; sensitive VO values kept out of dumps.
- **FR-PORT-002**: Build-time gates choosing the deprecated interface, the 1.19 port or both (fail closed).
- **FR-PORT-003** (2.0, REQ-ARCH-001 rule 3): `RemovedPortsPass` handles the six host ports removed in 2.0 (deprecated since 1.19): an application alias of a removed id whose class implements the framework-free twin is accepted with a deprecation until 3.0 (the twin is aliased to that service); any other service still registered under, aliased to or decorating a removed id fails the build naming the service and the replacing port.
- **FR-ACC-001** (2.1.0, DDD-host checklist B8): `VaultAccessCheckInterface` / `VaultIdAccessChecker` gives a host the vault pages' access decisions (ownership, scope membership, active grants, access-check and read-only events) for a user given as a `VaultUserRef` and a resource given by exact id on the private manager, through `VaultAccessGuard`; a reference by id only is looked up again through the user directory port and an unknown user fails closed; stateless.
- **FR-CMD-001** (2.1.0, DDD-host checklist B1): id-based write ports `VaultItemCommandsInterface` / `VaultItemCommands`, `VaultFolderCommandsInterface` / `VaultFolderCommands`, `VaultGrantCommandsInterface` / `VaultGrantCommands` and `VaultTagCommandsInterface` / `VaultTagCommands` over the write services the vault pages use: actor as a `VaultUserRef`, resources by id, results as `VaultItemView` / `VaultFolderView` / `VaultGrantView` (never an entity); each call re-checks access, runs in one vault write unit, dispatches the same events and refuses with the pages' exceptions; shared steps in `Internal\VaultCommandsTrait`; `Internal\VaultRowLock` loads the row by exact id with `SELECT ... FOR UPDATE` inside a write unit (a vanished row is not found); stateless.
- **FR-QRY-001** (2.1.0, DDD-host checklist B7, REQ-SEC-006): read port `VaultItemQueryInterface` / `VaultItemQuery` lists, counts and looks up items by owner key (user id or scope key) and ids with `VaultItemCriteria` (filters, `VaultItemSort`, `VaultTrashFilter`, page) on the private manager, comparing owner id, item ids and exact titles byte for byte in PHP after the database; a system read that checks no access; `VaultItemPage` / `VaultItemView` carry metadata only (no payload value, ciphertext or secret hash); `Internal\VaultViews` builds the read models.
- **FR-EVT-005** (2.1.0, REQ-ARCH-001 rule 7): change notifications dispatched by `DeferredVaultEventDispatcher` are held while a transaction is open on the vault's connection (`Internal\VaultTransactionLevelInterface`, implemented by `VaultEntityManager`); `Internal\DeferredVaultEventBoundaryListener` empties them at the main request start, dispatches the committed ones and drops the uncommitted at request / message / command end.
- **FR-EVT-002**: `ITEM_CHANGED`, `FOLDER_CHANGED`, `TAG_CHANGED` with ids only, dispatched after the vault's own flush (REQ-ARCH-001 rule 4).
- **FR-DB-004**: Opt-in private entity manager (`database.private_entity_manager`): own registry and repository factory, savepoints around vault writes, rollback tracking, dropped at every unit-of-work boundary (REQ-ARCH-001 rule 6, REQ-RUNTIME-002).
- **FR-DB-005**: `database.conditional_mapping`: entities and tables of disabled features left out of mapping and schema tools; raw DBAL access to unmapped token rows.
- **FR-DB-006**: XML mapping of every entity for DDD hosts.

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (285/285 mapped, audited 2026-11-27 on v2.4.0; 7 co-located `*.test.ts` excluded).
- **SC-002**: Configuration keys in `docs/CONFIGURATION.md` match `Configuration.php`.
- **SC-003**: `composer qa` / `make release-check` pass in CI (PHPUnit, PHPStan, Vitest where applicable).
- **SC-004**: No Packagist-visible behavior change without spec, inventory, and test updates.

---

## Non-goals

- User management, authentication and SSO (owned by the host application).
- Automatic key management / HSM integration — key rotation is operational (`docs/ENCRYPTION-KEY-ROTATION.md`).
- Browser-extension client behavior: the reference client under `extension/` is a separate deliverable outside the `src/` inventory; this spec covers the extension API only.
- `demo/` trees (illustrative, not Packagist API).

## Validation

| Check | Command |
| --- | --- |
| Full QA | `make release-check` or `composer qa` |
| Code inventory audit | `find src -type f ! -path '*/assets/dist/*' ! -name '*.test.ts' \| wc -l` |
| TS tests | `pnpm test` or `make test-ts` (when assets present) |

When changing behavior, update this spec, `code-inventory.md`, integrator docs, and tests.
