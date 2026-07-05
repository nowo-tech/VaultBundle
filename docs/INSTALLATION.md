# Installation

## Requirements

- PHP 8.2+ with `ext-sodium`
- Symfony 7.4+ or 8.x
- Doctrine ORM 2.15+ or 3.x
- Doctrine Bundle 2.10+ (Symfony 7.x) or 3.0+ (Symfony 8.x)

## Composer

```bash
composer require nowo-tech/vault-bundle
```

## Symfony Flex recipe

When using Flex, the recipe registers:

- `config/packages/nowo_vault.yaml`
- `config/routes/nowo_vault.yaml`

Manual install:

```php
// config/bundles.php
Nowo\VaultBundle\VaultBundle::class => ['all' => true],
```

```yaml
# config/routes/nowo_vault.yaml
nowo_vault:
    resource: .
    type: nowo_vault
```

## Encryption key

Generate a base64-encoded 32-byte libsodium key and set it in config or env:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

```yaml
# config/packages/nowo_vault.yaml
nowo_vault:
    encryption_key: '%env(VAULT_ENCRYPTION_KEY)%'
```

## Doctrine schema

Configure `user_class` and `table_prefix`, then update schema:

```bash
php bin/console doctrine:schema:update --force
# or create a migration
```

Default tables: `{table_prefix}_items`, `{table_prefix}_folders`, `{table_prefix}_grants`, `{table_prefix}_tags`, `{table_prefix}_item_tag`, `{table_prefix}_settings` (e.g. `vault_items`). The settings table is created when `config_storage.enabled: true`.

## Security firewall

Manage routes require authentication:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/tools/vault, roles: ROLE_USER }
```

See [Configuration](CONFIGURATION.md) for `VaultAccessCheckerInterface` and [Access control events](examples/AccessControl.md) for team/grant/read-only integration via Symfony events.

## Assets

Install bundle public assets:

```bash
php bin/console assets:install
```

Templates load `asset('vault.js', 'nowo_vault')` — rebuild with `pnpm run build` in the bundle repo if you fork it.

## Browser extension (optional)

When `browser_extension.enabled: true`, apply schema/migrations for `{table_prefix}_extension_tokens` and schedule token cleanup:

```bash
php bin/console nowo:vault:extension-tokens:purge
```

See [Browser extension](BROWSER-EXTENSION.md) and [Configuration](CONFIGURATION.md#browser-extension).

## Demo

See [demo/README.md](../demo/README.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
