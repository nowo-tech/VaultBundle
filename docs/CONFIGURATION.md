# Configuration

All options under `nowo_vault` in `config/packages/nowo_vault.yaml`.

## Table of contents

- [Required](#required)
- [Database](#database)
- [Security](#security)
- [Teams](#teams)
- [Attachments](#attachments)
- [Password field](#password-field)
- [Routes](#routes)
- [Pagination](#pagination)
- [Clock and logger](#clock-and-logger)
- [Manage Web UI (`web_ui`)](#manage-web-ui-web_ui)
  - [Layout and project chrome](#layout-and-project-chrome)
  - [CSS stack, icons and row actions](#css-stack-icons-and-row-actions)
  - [Per-page templates](#per-page-templates)
  - [Deprecated keys (1.15)](#deprecated-keys-115)
- [CSRF protection](#csrf-protection)
- [Firewall](#firewall)
- [Database-backed runtime configuration](#database-backed-runtime-configuration)
  - [YAML-only (bootstrap — never stored in DB)](#yaml-only-bootstrap--never-stored-in-db)
  - [DB-eligible (optional overrides in `{table_prefix}_settings`)](#db-eligible-optional-overrides-in-table_prefix_settings)
  - [Updating from your application](#updating-from-your-application)
- [Browser extension](#browser-extension)
  - [Purging expired tokens](#purging-expired-tokens)

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

An empty role list never grants access (the default checker denies it). When **every** list (`admin_roles`,
`access_roles`, `create_roles`, `list_roles`, `delete_roles`) is empty, `allow_unauthenticated` is `false` and no
`access_checker` is configured, the container refuses to compile (nobody could use the vault).

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

All routes are configurable under `nowo_vault.routes` and `nowo_vault.browser_extension.routes` (`path` + `name` per key; see `Configuration.php` defaults) and `web_ui.path_prefix` (formerly `route_prefix`) is prepended to every path. Import them once with the loader type `nowo_vault` (see [INSTALLATION.md](INSTALLATION.md)).

Each controller action declares its route with a Symfony `#[Route]` attribute (REQ-SF-004): HTTP methods, requirements, the default path / name and the configuration key (`options: {nowo_vault_route: <key>}`). The `nowo_vault` loader reads those attributes and applies the configured `path` / `name`, so the attribute defaults equal the configuration defaults and existing overrides keep working. Do **not** import `vendor/nowo-tech/vault-bundle/src/Controller/` as an `attribute` resource: that would register the routes a second time with the default paths, ignoring your configuration.

## Pagination

| Option | Default | Description |
|--------|---------|-------------|
| `pagination.per_page` | `50` | Items per page on the items, shared and trash lists (`?page=N`, 1–1000). Search results are capped at 50 and not paginated |

```yaml
nowo_vault:
    pagination:
        per_page: 25
```

## Clock and logger

Services that compare times (extension token expiry and last-use touch) use the `Psr\Clock\ClockInterface` service when the host registers one (FrameworkBundle does with `symfony/clock`), so tests can freeze time; without it they use the system clock. Doctrine entities still stamp `createdAt` / `updatedAt` / `deletedAt` with the system time (entities cannot receive services, REQ-ORM-001); `VaultExtensionToken::isExpired()` / `touch()` accept an optional `$now`.

Sensitive operations log through the injected `logger` service (`Psr\Log\LoggerInterface`, default Monolog channel `app` when MonologBundle is installed; nothing is logged without a logger). See [SECURITY.md](SECURITY.md#logging) for what is and is not logged.

## Manage Web UI (`web_ui`)

The manage pages follow the UiKit page contract (REQ-UI-003): each page extends a UiKit scaffold
(`@NowoUiKit/page/{dashboard,list,form,settings}.html.twig`) and the host themes every bundle page once in
`nowo_ui_kit` (layouts, CSS stack, theme slots and tokens). Page → scaffold map, data blocks and view models:
[TEMPLATES.md](TEMPLATES.md).

| Option | Default | Description |
|--------|---------|-------------|
| `web_ui.enabled` | `true` | Register the manage UI routes (home, items, shared, trash, item forms, sharing, runtime config). `false` keeps only the browser-extension API |
| `web_ui.path_prefix` | `''` | Prefix prepended to every vault route path (manage UI and browser-extension API), e.g. `/admin` |
| `web_ui.layout_template` | `null` | Layout the pages extend. `null` = `nowo_ui_kit.layouts.admin` (recommended). A 1.x layout without the UiKit canonical blocks is wrapped in a deprecated bridge |
| `web_ui.templates.<page>` | `{}` | Per-page template override: `home`, `items`, `item_form`, `trash`, `shared`, `share`, `runtime_config`. Unknown page ids are rejected |

```yaml
nowo_vault:
    web_ui:
        path_prefix: ''             # e.g. /admin
        layout_template: ~          # null = nowo_ui_kit.layouts.admin
```

All `web_ui` keys are YAML-only (never stored in the runtime DB settings).

### Layout and project chrome

Set the project layout **once** for every Nowo bundle in UiKit; the layout (or a one-file bridge) renders the canonical
blocks `nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_page_header`, `nowo_ui_flashes`,
`nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`:

```yaml
nowo_ui_kit:
    layouts:
        admin: 'layouts/admin.html.twig'
```

```twig
{# templates/layouts/admin.html.twig — bridge to the project layout #}
{% extends 'base.html.twig' %}
{% use '@NowoUiKit/layout/_bridge.html.twig' %}

{% block title %}{{ block('nowo_ui_bridge_title') }}{% endblock %}
{% block stylesheets %}{{ parent() }}<link rel="stylesheet" href="{{ asset('css/nowo-ui.css', 'nowo_ui_kit') }}">{{ nowo_ui_tokens() }}{{ block('nowo_ui_bridge_assets') }}{% endblock %}
{% block body %}{{ block('nowo_ui_bridge_body') }}{% endblock %}
{% block javascripts %}{{ parent() }}{{ include(nowo_ui_partial('scripts', nowo_ui_bundle|default(null))) }}{{ block('nowo_ui_bridge_end') }}{% endblock %}
```

Only when the vault pages must use a **different** layout than the other bundles, set
`nowo_vault.web_ui.layout_template`. The vault pages stack their own assets in the canonical blocks with
`{{ parent() }}`: `vault.css` (asset package `nowo_vault`) in `nowo_ui_styles`, `vault.js` in `nowo_ui_scripts`; the
host layout keeps its global CSS / JS. The bundle ships no default layout and never loads a CSS framework from a CDN.

### CSS stack, icons and row actions

Inherited from UiKit (one theme per host); the vault markup has no framework classes of its own (theme slots +
`nowo-ui-*` / `vault-*` hooks):

```yaml
nowo_ui_kit:
    css_framework: bootstrap5      # bootstrap5 | bootstrap4 | tabler | tailwind | foundation | custom | none
    icon_set: bootstrap-icons      # bootstrap-icons | tabler-icons | ux_icon | svg_inline | none
    row_actions_display: icon      # icon | text | icon_text
    theme:
        tokens: { primary: 'var(--brand)' }
        bundles:
            NowoVaultBundle: { classes: { table: 'data-table data-table--dense' } }   # vault-only exception
```

Examples per stack:

| Stack | `nowo_ui_kit` |
| --- | --- |
| Bootstrap 5 / Tabler | `css_framework: bootstrap5` (or `tabler`), `icon_set: bootstrap-icons` (or `tabler-icons`) |
| Tailwind | `css_framework: tailwind`, `icon_set: svg_inline` |
| Foundation | `css_framework: foundation`, `icon_set: svg_inline` |
| Own styles | `css_framework: custom` (semantic `nowo-ui-*` only), `theme.classes` / `theme.tokens` map them to the design system |

Form fields use the FormKit profile `vault` (seeded by the bundle: `nowo-ui-input` on every field, translated
placeholders / help only); the host form theme (`twig.form_themes` / `nowo_form_kit.css_framework`) styles them.

### Per-page templates

```yaml
nowo_vault:
    web_ui:
        templates:
            items: 'vault/items.html.twig'   # extends '@!NowoVaultBundle/vault/items.html.twig', overrides one data block
```

### Deprecated keys (1.15)

Mapped onto `web_ui` before validation with a deprecation (removed in 2.0). Canonical keys set in the same file win.

| Deprecated | Replacement |
| --- | --- |
| `route_prefix` | `web_ui.path_prefix` |
| `templates.layout` | `web_ui.layout_template` (the 1.x default `@NowoVaultBundle/layout.html.twig` maps to `null`) |
| `templates.<page>` | `web_ui.templates.<page>` (`templates.index` → `home`; values equal to the bundle defaults are dropped) |
| `css_framework` | `nowo_ui_kit.css_framework` (+ `icon_set`). When set, it is copied there unless the host set those keys; the Twig global `nowo_vault_css_framework` stays (deprecated) |

## CSRF protection

The manage UI requires Symfony CSRF protection: every mutating action (trash, restore, purge, folder / tag actions,
sharing and revocation, runtime config, password generator) validates a token, and the item / share / folder forms use
the form CSRF extension. With `web_ui.enabled: true` the container **refuses to compile** when
`security.csrf.token_manager` or the form CSRF extension is missing, or `framework.form.csrf_protection` is `false`
(`CsrfProtectionPass`, fail closed). `symfony/security-csrf` is a hard dependency. An API-only install (browser
extension) sets `web_ui.enabled: false`.

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
| `routes`, `web_ui.*`, `dashboard_route`, `firewall` | Routing and UI bootstrap |
| `css_framework` | Deprecated look-and-feel hint (Twig global) |
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
| `browser_extension.max_login_items` | `1000` | Upper bound of login items (own first, most recently updated first, then shared) decrypted per `/logins` lookup (REQ-PERF-001). A warning is logged when the limit truncates the scan |
| `browser_extension.authenticator` | null | Custom `VaultBrowserExtensionAuthenticatorInterface` service id |
| `browser_extension.user_provider` | null | User provider for the default password authenticator |
| `browser_extension.firewall` | null | Firewall whose user checker the default authenticator runs (`security.user_checker.<firewall>`: the firewall `user_checker`, or its chain incl. UserKit's account-status checker). Null = auto: the firewall named `main` if present, else the first firewall with security enabled. An unknown firewall fails at container compile time. Ignored when `user_checker` is set |
| `browser_extension.user_checker` | null | Explicit user checker service id run by the default authenticator (`checkPreAuth` before the password, `checkPostAuth` after); wins over `firewall`. Null resolves the firewall checker (see `firewall`); only without any secured firewall does it fall back to the `UserCheckerInterface` alias |
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
        # firewall: main          # optional; defaults to "main" or the first secured firewall
        # user_checker: app.checker  # optional explicit override (wins over firewall)
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
