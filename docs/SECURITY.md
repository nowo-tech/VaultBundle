# Security

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
- `cors_allowed_origins`: empty allows `chrome-extension://` / `moz-extension://` only. Wildcard `*` is ignored in `prod` (factory rejects it). Never rely on `*` outside local demos.

## Secrets and CLI

- Never commit `VAULT_ENCRYPTION_KEY` or production `.env`
- Demo `.env.example` uses a placeholder key for local use only
- `nowo:vault:reencrypt --old-key=…` / `--new-key=…` may expose key material on the process argv — **prefer `--old-key-file` / `--new-key-file`** (file wins when both argv and file options are set). Rotate shell history after any argv use.

## Accepted residuals (REQ-SEC-004)

Documented and accepted for **Pass (conditional)** / overall **Medium**:

1. Plaintext payloads exist in PHP memory briefly after decrypt (inherent).
2. Extension Bearer API has no CSRF (token auth by design).
3. Operator CLI key-on-argv risk when using `--old-key` / `--new-key` instead of `--*-key-file` (documented above; file-based options are the supported operator path).

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
| **AI security audit (REQ-SEC-004)** | Pass (conditional) recorded in monorepo `BUNDLES_SECURITY_ANALYSIS.md`. |

See [examples/AccessControl.md](examples/AccessControl.md).
