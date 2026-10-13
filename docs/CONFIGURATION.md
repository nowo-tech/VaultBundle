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
| `security.access_roles` | `[ROLE_USER]` | Open manage UI (hosts may set `ROLE_ADMIN` for admin-only) |
| `security.create_roles` | `[ROLE_USER]` | Create items/folders |
| `security.list_roles` | `[ROLE_USER]` | List vault |
| `security.delete_roles` | `[ROLE_USER]` | Trash / purge / share admin |
| `security.allow_unauthenticated` | `false` | DEV/DEMO only — wires AllowAll checker; production MUST keep `false` |

Manage UI requires `symfony/security-bundle` when `allow_unauthenticated` is `false`.

## Teams

| Option | Default | Description |
|--------|---------|-------------|
| `team_membership_resolver` | null | Service implementing `VaultTeamMembershipResolverInterface`; null = no teams |

## Attachments

| Option | Default | Description |
|--------|---------|-------------|
| `max_attachment_bytes` | `512000` | Max document attachment size (stored encrypted in payload) |

## Password field

Options for the login item password field. They apply only when `nowo-tech/password-strength-bundle` is installed (the field is then a `PasswordStrengthType`); without it the bundle renders a plain password input and ignores these keys.

| Option | Default | Description |
|--------|---------|-------------|
| `password_field.level` | `medium` | Strength meter level and validator policy (`weak`, `medium`, `strong`). DB-eligible (runtime config screen). |
| `password_field.generator_mode` | `input` | Password generator shown next to the field: `off` (no generator), `input` (the generated password is written straight into the input) or `modal` (the generated password is shown in a modal the user confirms). YAML-only. |
| `password_field.use_password_toggle` | `true` | Show the show/hide toggle when `nowo-tech/password-toggle-bundle` is installed (passed to the field's `use_password_toggle` option); `false` keeps a plain masked input. YAML-only. |

```yaml
nowo_vault:
    password_field:
        level: strong
        generator_mode: modal
        use_password_toggle: true
```

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

## Look-and-feel (REQ-UI-001)

| Option | Default | Description |
|--------|---------|-------------|
| `css_framework` | `tabler` | Host CSS stack hint: `bootstrap5`, `bootstrap`, `bootstrap4`, `tabler`, `tailwind`, `foundation`, `custom`, `none`. Twig global `nowo_vault_css_framework`. Matches the demo Tabler CDN layout. YAML-only (not stored in runtime DB settings). |

```yaml
nowo_vault:
    css_framework: bootstrap5   # or: tabler | tailwind | foundation | custom
    templates:
        layout: 'kit/vault_layout.html.twig'   # host chrome; pages keep stacking vault.css via base
```

## Firewall

Document `nowo_vault.firewall` in your `security.yaml`. Protect manage routes with host `access_control` (and keep `allow_unauthenticated: false` in production). Feature checks use `VaultAccessCheckerInterface` inside the controllers.

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
| `css_framework` | Look-and-feel hint (Twig global) |
| `security.*_roles` | Role lists (not `access_checker`) |

When storing `encryption_key` in the database, install `nowo-tech/doctrine-encrypt-bundle` and configure a bootstrap Halite/Defuse key (`DOCTRINE_ENCRYPT_KEY` or secret file). The column `vault_settings.encryption_key` is encrypted at rest via `#[Encrypted]`. Without that bundle the key is **not** stored in the database: `VaultRuntimeConfigWriter` refuses it, the runtime config screen shows a warning, and `nowo:vault:reencrypt --persist-new-key` exits before re-encrypting.

The runtime config screen only stores a key when **no key exists yet** (neither YAML/env `nowo_vault.encryption_key` nor a DB key), and only a valid base64 32-byte libsodium key. Once a key encrypts items, rotate it with `nowo:vault:reencrypt --persist-new-key` (see [ENCRYPTION-KEY-ROTATION.md](ENCRYPTION-KEY-ROTATION.md)); generating or pasting a new key would make existing items undecryptable.

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
| `browser_extension.user_checker` | null | User checker run by the default authenticator (`checkPreAuth` before the password, `checkPostAuth` after). Null uses the `UserCheckerInterface` alias (`security.user_checker`); set `security.user_checker.<firewall>` to reuse your firewall's checker (disabled / locked accounts) |
| `browser_extension.cors_allowed_origins` | `[]` | CORS whitelist; empty allows any `chrome-extension://` and `moz-extension://` origin; when set, only the listed origins (list your extension ID, e.g. `chrome-extension://<id>`); use `*` in dev only |
| `browser_extension.login_rate_limit.enabled` | `true` | Cache-backed brute-force protection on login |
| `browser_extension.login_rate_limit.max_attempts` | `5` | Failed logins per client IP + username before lockout |
| `browser_extension.login_rate_limit.max_attempts_per_ip` | `20` | Failed logins per client IP across **all** usernames before that IP is locked out (credential stuffing). A successful login does not clear this counter. `0` disables the per-IP bucket. Raise it when many users share one egress IP (NAT, proxy) |
| `browser_extension.login_rate_limit.interval_seconds` | `900` | Lockout window (seconds) |
| `browser_extension.login_rate_limit.cache_pool` | `cache.app` | Symfony cache pool for attempt counters |

Example:

```yaml
nowo_vault:
    browser_extension:
        enabled: true
        user_provider: security.user.provider.concrete.app_user_provider
        user_checker: security.user_checker.main   # firewall user checker (disabled/locked users)
        token_ttl: 86400
        cors_allowed_origins: []   # any extension origin; list chrome-extension://<id> to pin yours
        login_rate_limit:
            max_attempts: 5
            max_attempts_per_ip: 20
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
