# Upgrading

This document describes how to upgrade between versions of Vault Bundle.

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

[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
