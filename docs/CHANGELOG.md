# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
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

### Changed

- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `check-twig-extra`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `validate-translations`, `coverage-check`, demo release-check, `test-ts`). `check-no-raw-html-form` (REQ-TWIG-005) is wired once the remaining raw form markup is migrated.
- Internal: applied the pending Rector suggestions (`VaultMetadataListener` private static helper made non-static, `readonly` on the final `VaultAccessGuard`, early return in `VaultTagService` name normalisation, redundant `@var` dropped in `VaultItemFormData`); no behaviour change. `make rector-dry` is clean again.

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

[Unreleased]: https://github.com/nowo-tech/VaultBundle/compare/v1.8.2...HEAD
[1.3.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.3.0
[1.2.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.2.0
[1.1.5]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.5
[1.1.4]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.4
[1.1.3]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
