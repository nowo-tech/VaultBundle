# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/nowo-tech/VaultBundle/compare/v1.1.2...HEAD
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
