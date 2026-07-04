# Vault Bundle — Demo

Symfony 8.1 demo with FrankenPHP + MySQL.

## Quick start

```bash
cp .env.example .env   # if missing
make up
```

Default URL: `http://localhost:8022` (see `PORT` in `.env.example`).

Opens **directly** on the Vault manage UI (`/tools/vault`). A demo user is signed in automatically (no login form).

**File shares** are enabled by default: ciphertext is stored under `var/vault-storage/` (gitignored, not web-accessible). See [Local storage example](../docs/examples/LocalStorage.md).

## What to try

1. Create a text or file share; copy the generated link.
2. Open the link in a private window (with `#fragment` if embedded).
3. Revoke a share from the manage list.

Public routes (`/share/*`) work without authentication.

## Commands

| Target | Description |
|--------|-------------|
| `make up` | Start stack, migrate, load fixtures |
| `make down` | Stop containers |
| `make update-bundle` | Refresh path-repo bundle autoload |
| `make shell` | Shell in PHP container |

The bundle is mounted at `/var/vault-bundle` inside the container (path repository).
