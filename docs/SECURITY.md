# Security

## Table of contents

- [Threat model](#threat-model)
- [Access control](#access-control)
- [Browser extension API](#browser-extension-api)
- [Secrets and CLI](#secrets-and-cli)
- [Secret fields](#secret-fields)
- [Scope vaults and temporary grants (1.16.0)](#scope-vaults-and-temporary-grants-1160)
- [Reveal, copy and attachments (1.16.0)](#reveal-copy-and-attachments-1160)
- [Catalogue leakage](#catalogue-leakage)
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
| Item/folder ACL | IDOR | Owner (user or scope membership) + active `VaultGrant` + events |
| Scope membership | A user who left a scope keeps access | Membership resolved on every request (`VaultScopeAccessResolverInterface`, memoized per request only); a non-member is denied everything, grants included |
| Temporary grants | Access after expiry, escalation | Expiry checked on every read against the clock (no purge lag); temporary grants capped at `write`; never replace a permanent grant |
| Secret fields | Disclosure in HTML / caches | Never rendered; per-field reveal (CSRF, View, rate limit, `ITEM_REVEALED`); `no-store` on every bundle response |
| Share form | Cross-tenant grants | Fail closed without a `GRANT_LIST_QUERY` listener |
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

## Secret fields

Since 1.15.2 no decrypted secret reaches the item HTML. Secret fields per type: login `password`, `note`; secure note `content`, `note`; card `cardNumber`, `cvv`, `cardPin`, `note`; contact `note`; identity documents `documentNumber`, `note`. They render empty (placeholder "stored value hidden"); an empty submit keeps the stored value, **Clear stored values** empties it. A value is returned only by `POST <item_reveal>` (`/tools/vault/items/{id}/reveal/{field}`): CSRF token `vault_reveal_<item id>` in `X-CSRF-Token`, View access on the item, a secret field of the item type (anything else 404), `security.secret_access_rate_limit` per user, `Cache-Control: no-store, private`, `X-Content-Type-Options: nosniff`. Each reveal dispatches `VaultEvents::ITEM_REVEALED` (ids, field names, channel; never the value); the browser extension `/logins` dispatches it once per returned credential (channel `extension`) and shares the same per-user budget (one bucket for both channels; one `/logins` call is one unit, whatever the number of credentials it returns). The bucket is keyed by user id, or by user identifier when the user object has no id. The limit is best effort: it needs a cache pool shared by all workers and persistent, and concurrent requests can slightly exceed it. Every bundle response carries `Cache-Control: no-store, private`, `Pragma: no-cache` and `X-Content-Type-Options: nosniff`.

Sharing fails closed: without a `GRANT_LIST_QUERY` listener listing grantees nobody can be granted (`security.allow_unlisted_grantees: true` restores the unsafe free-text form). `security.deny_status: 404` hides whether an item or folder exists from users without access.

## Scope vaults and temporary grants (1.16.0)

| Area | Threat | Control |
| --- | --- | --- |
| Grants: escalation | A temporary grantee mints permanent grants that outlive its expiry | Temporary grants are capped at `write` by the config tree (`grants.temporary.max_permission`); Share needs `admin` |
| Grants: escalation | A `write` grantee moves an item into its own folder to inherit folder grants (GHSA-vq49-95v8-97mg) | Folder grants apply only when the folder has the item's owner; folder / tag changes need the personal owner or scope admin |
| Grants: escalation | Self-grant, or a temporary grant that downgrades or replaces a permanent one | `self_grant`; a covered temporary grant is `unchanged`, an upgrade is refused (`would_replace_permanent`) |
| Grants: escalation | A host approval acts with a stale approver | `grantAs()` re-checks Share at the call |
| Grants: escalation (residual R3) | A demoted scope admin keeps what they granted to their own team | Accepted. Veto in `GRANT_POLICY_CHECK`; the baseline still drops with the role |
| Grants: expiry | An expired grant works until the purge | Filtered in every query **and** re-checked with `VaultGrant::isActiveAt()` against `ClockInterface`; the purge only deletes rows and emits `GRANT_EXPIRED` |
| Grants: expiry | Clock skew between web nodes and the database | `now` comes from PHP (`ClockInterface`), never from the database; run NTP. Active while `now < expiresAt` |
| Grants: races | Double revoke, revoke vs purge, extend vs purge | Conditional `DELETE … WHERE id` (and `expires_at <= now` for the purge) with an affected-rows check: one event per deletion; an extended grant survives |
| Grants: races (residual R1) | A revoke lands while a request is between the access check and the response | Accepted: the window is one request |
| Grants: cross-tenant | Any typed grantee id is accepted | Fail closed without a `GRANT_LIST_QUERY` listener (1.15.2); a user grantee of a scope resource must be a scope member (`grantee_not_in_scope`) |
| Grants: cross-tenant | A user who left an organization keeps item grants | The membership gate runs before grants on every request |
| Grants: cross-tenant | Team ids collide across organizations | Host obligation: namespace team ids (e.g. `org-<uuid>:group-<uuid>`); the bundle compares them opaquely |
| Scopes | A forged `{scope}` in the URL | Route requirement `[a-z0-9][a-z0-9_-]{0,127}` + `resolve()` on every request; the scope key is never read from form data; a non-member gets the deny status |
| Scopes (SEC-004 FQ-L1) | A grants-only member (no baseline) learns the scope's folder and tag names from the scope page | The page lists only the folders and tags of the items the member may View (folders with their ancestors) and the folders the member may Share; members with a baseline see all of them |
| Scope tags (SEC-004 FQ-I4) | A user with Admin on one scope item (a scope admin, or an Admin grant) creates scope tags by tagging it; the names then show to members and in the catalogue | Accepted: tagging is an item edit. Hosts that want tag creation restricted to `create` members veto Admin grants in `GRANT_POLICY_CHECK` or keep item grants at `read` / `write` |
| Ownership | Scope rows deleted with their creator | `creator_id` is NULL on scope rows; `created_by_id` is `ON DELETE SET NULL` (audit only, never used for access) |

Accepted residuals added in 1.16.0: **R1** the in-flight request at revoke time; **R2** plaintext in PHP memory while a value is decrypted (reveal, copy, attachment, `/logins`, and the health report and decrypted export of later blocks), inherent to server-side encryption; **R3** team self-grants after a demotion.

## Reveal, copy and attachments (1.16.0)

| Threat | Control |
| --- | --- |
| Field probing, or a field a read-only grantee should not see | Per-type allow-list (`reveal.revealable_fields`, default the secret fields; anything else 404 like an inaccessible item) and the `ITEM_FIELD_REVEAL_CHECK` veto |
| Cross-site POST, `<img>` or link prefetch triggers reveals | POST only, per-item CSRF token in a header, no GET route; no CORS on these routes |
| A compromised session dumps every secret | `security.secret_access_rate_limit` plus `reveal.rate_limit` (per minute, per hour, per item and field), `REVEAL_THROTTLED`, an audit event per reveal |
| Values in logs, events, exceptions, the profiler, flashes or caches | Events carry no value (reflection test); `VaultRevealedValue` dumps `***` and refuses serialization; `VaultDecryptionFailedException` has a generic message and no previous exception; `#[\SensitiveParameter]`; the profiler is disabled on these routes; `no-store` |
| The value stays in the DOM or the back/forward cache | Auto-hide, wipe on `pagehide` / hidden tab / dialog close, Copy without the DOM, `no-store` |
| The reveal succeeds although the audit write failed | A throwing audit listener refuses the reveal (503): no audit, no reveal |
| A downloaded file is rendered in the page origin or carries user data in its name | Always `application/octet-stream`, `nosniff`, `Content-Disposition: attachment` with a sanitised basename, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow` |
| Decrypt during a key rotation | Fails closed with a generic 503 |

## Catalogue leakage

The catalogue is metadata only by construction (scalar queries: no item, no ciphertext is loaded), but what it shows
is disclosed to every member with the `catalogue` flag:

1. **Titles.** Users put hosts, account emails or even passwords in titles. The item form of a scope item warns about
   it; the title stays searchable by design.
2. **Existence and inventory.** Members learn which services the scope uses and how many credentials exist per type
   (`item_type` reveals that a card, passport or ID document is stored). Use *Hide from the catalogue*
   (`catalogue_hidden`) per item; keep identity documents in personal vaults.
3. **Change timing.** The date (not the time) of the last change is shown.
4. **Tags** can name environments ("prod", "root").
5. **Enumeration.** Item ids are random UUIDs and every item route checks access; with `deny_status: 404` an id
   confirms nothing.
6. **No URL leak.** `websites` stays encrypted and never reaches the catalogue.

Accepted residual **R5**: catalogue metadata is disclosed to scope members, by design.

## Logging

The bundle logs through the injected `Psr\Log\LoggerInterface` (`logger` service; no-op when none is registered). Every record carries `bundle: nowo_vault` and an `action` key; context holds identifiers and counters only.

| Event | Level | Context |
|-------|-------|---------|
| Extension login rejected (bad credentials / account or Vault role denied) | notice | `reason` only — **never** the submitted username or password |
| Extension token issued / all tokens of a user revoked | info | `user_id`, `count` |
| `/logins` lookup truncated by `max_login_items` | warning | `user_id`, `available`, `scanned` |
| Grant created / updated / revoked | info | `resource_type`, `resource_id`, `grantee_type`, `grantee_id`, `permission`; since 1.16.0 also `expires_at` and `actor_id` when set |
| Grant refused (1.16.0) | notice | `code`, `actor_id`, `resource_type`, `resource_id` |
| Expired grants purged (1.16.0) | info | `count`, `dry_run` |
| Field revealed / copied / attachment downloaded (1.16.0) | info | `user_id`, `item_id`, `field`, `purpose`, `channel` |
| Reveal throttled (1.16.0) | warning | `user_id`, `item_id`, `window` |
| Reveal refused: audit listener failed (1.16.0) | error | `user_id`, `item_id`, exception **class** |
| Item / folder purged | info | `item_id` / `folder_id` |
| Runtime configuration updated / reset | notice | changed top-level **keys** and `encryption_key_change` (bool) — never the values |
| `nowo:vault:reencrypt` started / finished / rejected / failed; key persisted to DB | info / error / notice | `dry_run`, `include_deleted`, counters, error message (no key material) |
| `nowo:vault:extension-tokens:purge` | info | `count` |

Never logged: master keys (YAML, DB or CLI options), item payloads (passwords, notes, attachments, card data), item titles, Bearer tokens or their hashes, usernames typed in the extension, session ids; since 1.16.0 also revealed values and the file names of attachments (they may contain personal data). Route your Monolog handlers so these records do not reach third parties you would not trust with resource ids.

## Accepted residuals (REQ-SEC-004)

Documented and accepted for **Pass (good)** / overall **Low** (re-audit **2026-08-20**):

1. **R2:** plaintext payloads exist in PHP memory briefly after decrypt (inherent).
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
