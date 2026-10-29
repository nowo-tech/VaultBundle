# Security

## Table of contents

- [Threat model](#threat-model)
- [Access control](#access-control)
- [Browser extension API](#browser-extension-api)
- [Secrets and CLI](#secrets-and-cli)
- [Logging](#logging)
- [Accepted residuals (REQ-SEC-004)](#accepted-residuals-req-sec-004)
- [Release checklist (12.4.1)](#release-checklist-1241)

## Threat model

| Asset | Risk | Mitigation |
|-------|------|------------|
| Vault payloads | Disclosure at rest | libsodium secretbox (`encryption_key`); DB stores ciphertext only |
| Master key | Server compromise | Store `VAULT_ENCRYPTION_KEY` in secrets manager; [rotate with a re-encryption plan](ENCRYPTION-KEY-ROTATION.md) |
| Extension login | Brute force | `browser_extension.login_rate_limit` (cache-backed, enabled by default) |
| Extension tokens | DB growth / leaked tokens | Short `token_ttl`, cron `nowo:vault:extension-tokens:purge`, HTTPS |
| Manage routes | Unauthorized access | Symfony firewall + `VaultAccessCheckerInterface`. Keep `security.allow_unauthenticated: false` in production (demo-only AllowAll bypass). |
| Item/folder ACL | IDOR | Creator ownership + `VaultGrant` + events |
| Attachments | Oversized uploads | `max_attachment_bytes` limit |
| Team grants | Wrong team access | Implement `VaultTeamMembershipResolverInterface` carefully |

## Access control

Default: **creator-only**. Extend via:

- `VaultGrant` (user/team + read/write/admin)
- `VaultEvents::ITEM_ACCESS_CHECK`, `FOLDER_ACCESS_CHECK`
- `VaultEvents::ITEM_READ_ONLY_RESOLVE` for view-only mode

## Browser extension API

- Extension endpoints use **Bearer tokens** (not cookie sessions). CSRF does not apply to Bearer API calls; protect tokens (HTTPS, short TTL, purge cron) and keep login rate limits enabled.
- `cors_allowed_origins`: empty allows any `chrome-extension://` / `moz-extension://` origin; a non-empty list allows only the listed origins (other extensions are rejected).
- Extension login runs the firewall user checker (`security.user_checker.<firewall>`, firewall from `browser_extension.firewall`, default `main` or the first secured firewall; `browser_extension.user_checker` overrides it) so disabled or locked users cannot obtain Bearer tokens. Before the Unreleased fix the default fell back to the no-op global `security.user_checker` (or no checker at all when a firewall set a custom `user_checker`): upgrade, or set `browser_extension.user_checker` explicitly. Wildcard `*` is ignored in `prod` (factory rejects it). Never rely on `*` outside local demos.

## Secrets and CLI

- The runtime config screen never replaces an existing master key (YAML/env or DB) and validates pasted keys (base64, 32 bytes). Rotation goes through `nowo:vault:reencrypt --persist-new-key`.
- The master key is stored in `vault_settings.encryption_key` only when `nowo-tech/doctrine-encrypt-bundle` is installed (encryption at rest); otherwise storage is refused.

- Never commit `VAULT_ENCRYPTION_KEY` or production `.env`
- Demo `.env.example` uses a placeholder key for local use only
- `nowo:vault:reencrypt --old-key=…` / `--new-key=…` may expose key material on the process argv — **prefer `--old-key-file` / `--new-key-file`** (file wins when both argv and file options are set). Rotate shell history after any argv use.

## Logging

The bundle logs through the injected `Psr\Log\LoggerInterface` (`logger` service; no-op when none is registered). Every record carries `bundle: nowo_vault` and an `action` key; context holds identifiers and counters only.

| Event | Level | Context |
|-------|-------|---------|
| Extension login rejected (bad credentials / account or Vault role denied) | notice | `reason` only — **never** the submitted username or password |
| Extension token issued / all tokens of a user revoked | info | `user_id`, `count` |
| `/logins` lookup truncated by `max_login_items` | warning | `user_id`, `available`, `scanned` |
| Grant created / updated / revoked | info | `resource_type`, `resource_id`, `grantee_type`, `grantee_id`, `permission` |
| Item / folder purged | info | `item_id` / `folder_id` |
| Runtime configuration updated / reset | notice | changed top-level **keys** and `encryption_key_change` (bool) — never the values |
| `nowo:vault:reencrypt` started / finished / rejected / failed; key persisted to DB | info / error / notice | `dry_run`, `include_deleted`, counters, error message (no key material) |
| `nowo:vault:extension-tokens:purge` | info | `count` |

Never logged: master keys (YAML, DB or CLI options), item payloads (passwords, notes, attachments, card data), item titles, Bearer tokens or their hashes, usernames typed in the extension, session ids. Route your Monolog handlers so these records do not reach third parties you would not trust with resource ids.

## Accepted residuals (REQ-SEC-004)

Documented and accepted for **Pass (good)** / overall **Low** (re-audit **2026-08-20**):

1. Plaintext payloads exist in PHP memory briefly after decrypt (inherent).
2. Extension Bearer API has no CSRF (token auth by design; rate limit + TTL + purge cron).
3. Operator CLI key-on-argv risk when using `--old-key` / `--new-key` instead of `--*-key-file` (documented above; file-based options are the supported operator path).
4. Generated encryption keys are shown **one-time** via session (not flash); host must use HTTPS.

## Release checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README. |
| **`.gitignore` and `.env`** | `.env` and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No `VAULT_ENCRYPTION_KEY` or production credentials in tracked files. |
| **Recipe / Flex** | Demo `.env.example` uses placeholder keys only. |
| **Input / output** | Encryption at rest via libsodium; runtime key shown one-time only in UI. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Logs do not print encryption keys, tokens, or vault payloads. |
| **Cryptography** | Unique `VAULT_ENCRYPTION_KEY` per environment; rotation plan documented. |
| **Permissions / exposure** | `VaultAccessCheckerInterface` and grants configured for production roles. |
| **Limits / DoS** | `max_attachment_bytes`, extension login rate limit, token TTL and purge cron. |
| **AI security audit (REQ-SEC-004)** | **Pass (good)** / Low — re-audit 2026-08-20 (one-time session key, manage CSRF, Flex access_control, Bearer extension documented). |


See [examples/AccessControl.md](examples/AccessControl.md).
