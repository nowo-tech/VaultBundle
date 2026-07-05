# Configuration

All options under `nowo_vault` in `config/packages/nowo_vault.yaml`.

## Required

| Option | Description |
|--------|-------------|
| `user_class` | FQCN of User entity (`UserInterface` + `getId()`) |
| `encryption_key` | Base64-encoded 32-byte libsodium key for payload encryption (YAML/env bootstrap; optional encrypted DB override in `vault_settings.encryption_key` with `nowo-tech/doctrine-encrypt-bundle`) |

Generate a key:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

## Database

| Option | Default | Description |
|--------|---------|-------------|
| `table_prefix` | `vault_` | Prefix for vault tables (`vault_items`, `vault_folders`, `vault_grants`, `vault_tags`, `vault_item_tag`, `vault_settings`) |
| `database.driver` | `doctrine_orm` | Persistence driver (`doctrine_mongodb` is reserved — **not implemented yet**) |
| `database.platform` | `postgresql` | Documented platform (`mongodb` is **planned**; only PostgreSQL/MySQL/SQLite are supported today) |
| `database.entity_manager` | `default` | Doctrine ORM entity manager |

## Security

| Option | Default | Description |
|--------|---------|-------------|
| `security.access_checker` | null | Custom `VaultAccessCheckerInterface` service id |
| `security.admin_roles` | `[ROLE_ADMIN]` | Bypass feature checks |
| `security.access_roles` | `[ROLE_USER]` | Open manage UI |
| `security.create_roles` | `[ROLE_USER]` | Create items/folders |
| `security.list_roles` | `[ROLE_USER]` | List vault |
| `security.delete_roles` | `[ROLE_USER]` | Trash / purge / share admin |

## Teams

| Option | Default | Description |
|--------|---------|-------------|
| `team_membership_resolver` | null | Service implementing `VaultTeamMembershipResolverInterface`; null = no teams |

## Attachments

| Option | Default | Description |
|--------|---------|-------------|
| `max_attachment_bytes` | `512000` | Max document attachment size (stored encrypted in payload) |

## Routes

All routes are configurable under `nowo_vault.routes` (see `Configuration.php` defaults). Loader type: `nowo_vault`.

## Templates

| Key | Default |
|-----|---------|
| `layout` | `@NowoVaultBundle/layout.html.twig` |
| `home` | `@NowoVaultBundle/vault/home.html.twig` |
| `items` | `@NowoVaultBundle/vault/items.html.twig` |
| `index` | `@NowoVaultBundle/vault/home.html.twig` (deprecated alias of `home`) |
| `item_form` | `@NowoVaultBundle/vault/item_form.html.twig` |
| `trash` | `@NowoVaultBundle/vault/trash.html.twig` |
| `shared` | `@NowoVaultBundle/vault/shared.html.twig` |
| `share` | `@NowoVaultBundle/vault/share.html.twig` |
| `runtime_config` | `@NowoVaultBundle/vault/runtime_config.html.twig` |

## Firewall

Document `nowo_vault.firewall` in your `security.yaml`. All manage routes require authentication.

## Database-backed runtime configuration

Optional storage for **runtime** settings that can change without redeploying YAML. Bootstrap options always stay in YAML/env.

```yaml
nowo_vault:
    config_storage:
        enabled: true
        cache_pool: cache.app   # Symfony cache pool for merged config
    routes:
        runtime_config:
            path: /tools/vault/runtime-config
            name: nowo_vault_runtime_config
```

When `enabled: true`, Doctrine creates/uses `{table_prefix}_settings` (default `vault_settings`) with JSON overrides. The bundle merges **YAML baseline → DB overrides**, validates the result, and caches it under `nowo_vault.runtime_config.merged`. The manage UI exposes a runtime settings page at `routes.runtime_config` (admin roles only).

### YAML-only (bootstrap — never stored in DB)

| Key | Reason |
|-----|--------|
| `user_class` | Required before ORM metadata |
| `table_prefix` | Names the settings table itself |
| `database.*` | Connection bootstrap |
| `config_storage.*` | Enables/disables DB storage |
| `security.access_checker` | DI service id |
| `team_membership_resolver` | DI service id |
| `routes`, `templates`, `route_prefix`, `dashboard_route`, `firewall` | Routing and UI bootstrap |
| `security.*_roles` | Role lists (not `access_checker`) |

When storing `encryption_key` in the database, install `nowo-tech/doctrine-encrypt-bundle` and configure a bootstrap Halite/Defuse key (`DOCTRINE_ENCRYPT_KEY` or secret file). The column `vault_settings.encryption_key` is encrypted at rest via `#[Encrypted]`; YAML/env still provides the initial bootstrap until a DB override is saved.

### DB-eligible (optional overrides in `{table_prefix}_settings`)

Only these keys are persisted when using `VaultRuntimeConfigWriter`:

| Key | Notes |
|-----|-------|
| `encryption_key` | Stored in dedicated encrypted column (DoctrineEncryptBundle) |
| `max_attachment_bytes` | Upload limit (`config_values` JSON) |
| `password_field.level` | Password strength only (`weak`, `medium`, `strong`) |

All other configuration remains YAML/env-only and is not editable through the runtime settings UI.

### Updating from your application

Inject `VaultRuntimeConfigWriter` and persist partial overrides:

```php
use Nowo\VaultBundle\Config\VaultRuntimeConfigWriter;

final readonly class VaultSettingsManager
{
    public function __construct(private VaultRuntimeConfigWriter $writer) {}

    public function tightenUploadLimit(): void
    {
        $this->writer->update(['max_attachment_bytes' => 256_000]);
    }

    public function revertToYamlDefaults(): void
    {
        $this->writer->reset();
    }
}
```

Each `update()` or `reset()` **invalidates the Symfony cache** so the next request reloads from the database.

After changing `routes` in the database, run `bin/console cache:clear` so Symfony rebuilds the route cache.

Read effective config at runtime via `VaultRuntimeConfigProvider::get()`.

## Browser extension

Optional Chrome/Firefox extension API (Bearer token auth, not session CSRF). See [Browser extension guide](BROWSER-EXTENSION.md).

| Option | Default | Description |
|--------|---------|-------------|
| `browser_extension.enabled` | `false` | Register extension API routes |
| `browser_extension.token_ttl` | `86400` | Bearer token lifetime (seconds, min 60) |
| `browser_extension.authenticator` | null | Custom `VaultBrowserExtensionAuthenticatorInterface` service id |
| `browser_extension.user_provider` | null | User provider for the default password authenticator |
| `browser_extension.cors_allowed_origins` | `[]` | CORS whitelist; empty allows `chrome-extension://` and `moz-extension://` only; use `*` in dev only |
| `browser_extension.login_rate_limit.enabled` | `true` | Cache-backed brute-force protection on login |
| `browser_extension.login_rate_limit.max_attempts` | `5` | Failed logins before lockout |
| `browser_extension.login_rate_limit.interval_seconds` | `900` | Lockout window (seconds) |
| `browser_extension.login_rate_limit.cache_pool` | `cache.app` | Symfony cache pool for attempt counters |

Example:

```yaml
nowo_vault:
    browser_extension:
        enabled: true
        user_provider: security.user.provider.concrete.app_user_provider
        token_ttl: 86400
        cors_allowed_origins: []   # extension origins only in production
        login_rate_limit:
            max_attempts: 5
            interval_seconds: 900
```

### Purging expired tokens

Schedule hourly (or daily) cleanup of expired Bearer tokens:

```bash
php bin/console nowo:vault:extension-tokens:purge
```

See [Encryption key rotation](ENCRYPTION-KEY-ROTATION.md) for `nowo:vault:reencrypt`.

Example cron:

```cron
0 * * * * cd /var/www/app && php bin/console nowo:vault:extension-tokens:purge --no-interaction
```
