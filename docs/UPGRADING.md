# Upgrading

## Table of contents

- [Unreleased](#unreleased)
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

