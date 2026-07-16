# Upgrading

This document describes how to upgrade between versions of Vault Bundle.

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

[1.1.3]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.3
[1.1.2]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.2
[1.1.1]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.1
[1.1.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.1.0
[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
