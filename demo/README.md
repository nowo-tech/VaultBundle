# Vault Bundle — Demo

Symfony 8.1 demo with FrankenPHP + MySQL.

## Quick start

```bash
cd symfony8
cp .env.example .env   # if missing
make up
```

Default URL: `http://localhost:8023` (see `PORT` in `.env.example`).

Opens **directly** on the Vault manage UI (`/tools/vault`). A demo user is signed in automatically (no login form).

## Database-backed runtime configuration

This demo enables `nowo_vault.config_storage.enabled: true` in `symfony8/config/packages/nowo_vault.yaml`.

| What | Where |
|------|--------|
| YAML bootstrap | `symfony8/config/packages/nowo_vault.yaml` |
| DB overrides | MySQL table `vault_settings` (`config_values` JSON) |
| Demo UI | Bundle route `nowo_vault_runtime_config` (default `/tools/vault/runtime-config`) |
| Entry from vault home | Card **Runtime configuration (database)** |

Try changing `max_attachment_bytes` or `password_field.level`, save, then verify uploads and password strength in the vault UI.

Inspect raw rows:

```bash
cd symfony8
docker compose exec mysql mysql -udemo -pdemo vault_demo \
  -e "SELECT scope, config_values, updated_at FROM vault_settings;"
```

Secrets bootstrap:

| Variable | Purpose |
|----------|---------|
| `VAULT_ENCRYPTION_KEY` | Vault payload encryption (YAML/env; optional DB override) |
| `DOCTRINE_ENCRYPT_KEY` | Encrypts `vault_settings.encryption_key` at rest (Halite hex key) |

Inspect raw rows (note `encryption_key` is ciphertext + `<ENC>` marker):

```bash
cd symfony8
docker compose exec mysql mysql -udemo -pdemo vault_demo \
  -e "SELECT scope, LEFT(encryption_key, 40) AS encryption_key, config_values, updated_at FROM vault_settings;"
```

`user_class` and `table_prefix` always stay in YAML — never in the database.

## What to try

1. Create logins, notes, or documents from **My items**.
2. Tag entries and filter by tag.
3. Change runtime settings via **Runtime configuration** and confirm they apply without editing YAML.
4. Share items or folders with grants (demo user only by default).

## Commands

| Target | Description |
|--------|-------------|
| `make up-symfony8` | Start stack, migrate, load fixtures |
| `make down-symfony8` | Stop containers |
| `make update-bundle-symfony8` | Refresh path-repo bundle autoload |
| `make shell-symfony8` | Shell in PHP container |

The bundle is mounted at `/var/vault-bundle` inside the container (path repository).
