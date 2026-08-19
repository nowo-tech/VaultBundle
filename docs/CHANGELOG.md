# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/VaultBundle/compare/v1.4.1...HEAD
[1.3.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.3.0
[1.2.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.2.0
[1.1.5]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.5
[1.1.4]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.4
[1.1.3]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
