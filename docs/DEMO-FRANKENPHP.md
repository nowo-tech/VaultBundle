# Demo with FrankenPHP

The bundle includes a **Symfony 8** demo under `demo/symfony8/` using **FrankenPHP** and Docker Compose.

## Table of contents

- [Quick start](#quick-start)
- [Production / worker mode](#production--worker-mode)
- [Demo pages](#demo-pages)
- [Commands](#commands)
- [Troubleshooting](#troubleshooting)

## Quick start

From the bundle root:

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8023/tools/vault** (override with `PORT` in `demo/symfony8/.env`).

The demo auto-loads fixtures with a test user (see `demo/symfony8/README.md`).

In **development** (`APP_ENV=dev`), the container uses `Caddyfile.dev` without FrankenPHP worker so Twig and PHP changes appear on refresh.

## Production / worker mode

| File | Mode | Use |
|------|------|-----|
| `docker/frankenphp/Caddyfile` | **Worker** | Production-like performance |
| `docker/frankenphp/Caddyfile.dev` | **Request** | Local development |

To run with worker mode:

```bash
cd demo/symfony8
APP_ENV=prod APP_DEBUG=0 docker compose up -d --build
```

Vault UI is stateless for typical CRUD flows; encrypted payloads are stored server-side with the configured master key.

## Demo pages

| Route | Description |
|-------|-------------|
| `/tools/vault` | Vault home — logins, notes, cards, contacts, documents |
| `/tools/vault/shared` | Items shared with the current user |
| `/tools/vault/trash` | Deleted items (restore / purge) |
| `/tools/vault/items/new/{type}` | Create item by type |
| `/tools/vault/items/{id}/edit` | Edit item |
| `/tools/vault/items/{id}/share` | Share item with user or team |

## Commands

```bash
make -C demo/symfony8 up              # start (install + migrate + fixtures)
make -C demo/symfony8 down            # stop
make -C demo/symfony8 shell             # PHP container shell
make -C demo/symfony8 database-empty    # wipe DB, remigrate, reload fixtures
make -C demo/symfony8 update-bundle     # sync local bundle autoload
```

From bundle root:

```bash
make -C demo up-symfony8
make -C demo release-check              # healthcheck on port 8023
```

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Keep the worker Caddyfile (`php_server { worker ... }`) |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`, hot-reload friendly) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Troubleshooting

- **Port in use:** set `PORT=8024` (or another free port) in `demo/symfony8/.env`.
- **Stale assets:** `make -C demo/symfony8 update-bundle` and `make assets` at bundle root.
- **Missing encryption key:** copy `.env.example` to `.env` and set `VAULT_ENCRYPTION_KEY`.
- **Packagist / DNS in Docker:** demo `docker-compose.yml` sets public DNS for Composer inside the container.
