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
- **FR-VIEW-001**: `layout.html.twig` and `vault/base.html.twig` page shell (extends the controller layout, UiKit, REQ-UI-001).
- **FR-VIEW-002**: `VaultExtension` exposes the manage Web UI globals to Twig.
- **FR-BUILD-001**: Vite builds `Resources/public/vault.js` / `vault.css` from `Resources/assets/src/` (documented as outputs, not orphan files).
- **FR-WORKER-001**: FrankenPHP worker safety without kernel reset: repositories resolve the EntityManager per call and reopen a closed one (`ResolvesEntityManagerTrait`); no per-request state in shared services.

---

## Success Criteria

- **SC-001**: 100% of production files in `src/` appear in [`code-inventory.md`](code-inventory.md) with requirement IDs (123/123 mapped, audited 2026-10-20; 1 co-located `*.test.ts` excluded).
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
