# Browser extension

The VaultBundle ships a Chrome/Firefox extension under `extension/` that autofill login items from the vault by domain.

## Enable the API

```yaml
# config/packages/nowo_vault.yaml
nowo_vault:
    browser_extension:
        enabled: true
        user_provider: security.user.provider.concrete.app_user_provider
        token_ttl: 86400
        cors_allowed_origins: []   # production: extension schemes only
```

Expose the extension routes publicly in `security.yaml` (Bearer auth is enforced by the bundle, not Symfony session):

```yaml
security:
    access_control:
        - { path: ^/api/vault/extension, roles: PUBLIC_ACCESS }
```

Run Doctrine schema update so `{table_prefix}_extension_tokens` exists (included in demo migrations).

## Authentication flow

1. Extension POSTs `{ "username", "password" }` to `/api/vault/extension/login`.
2. On success, the API returns `{ "token", "expiresAt" }` (opaque Bearer token).
3. Subsequent requests send `Authorization: Bearer <token>`.
4. POST `/api/vault/extension/logout` revokes the token.

Session CSRF tokens **do not** apply to this API. Protect login with `browser_extension.login_rate_limit` (enabled by default).

## API endpoints

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/api/vault/extension/login` | Public | Issue Bearer token |
| GET | `/api/vault/extension/me` | Bearer | Current user id |
| GET | `/api/vault/extension/logins?domain=` | Bearer | Login items matching domain |
| POST | `/api/vault/extension/logout` | Bearer | Revoke token |

Routes and paths are configurable under `nowo_vault.browser_extension.routes`.

## CORS

By default (`cors_allowed_origins: []`), only `chrome-extension://` and `moz-extension://` origins receive CORS headers. For local development you may set `['*']` — never use wildcard in production.

## Rate limiting

Failed login attempts are counted per client IP + username in the configured Symfony cache pool. After `max_attempts` failures within `interval_seconds`, the API returns HTTP 429.

## Token maintenance

Expired tokens are ignored at lookup but remain in the database until purged:

```bash
php bin/console nowo:vault:extension-tokens:purge
```

Schedule via cron (see [Configuration — Purging expired tokens](CONFIGURATION.md#purging-expired-tokens)).

Demo shortcuts:

```bash
make -C demo/symfony8 vault-purge-tokens
VAULT_OLD_KEY='...' make -C demo/symfony8 vault-reencrypt-dry-run
```

## Custom authentication

Implement `VaultBrowserExtensionAuthenticatorInterface` or listen to `VaultEvents::BROWSER_EXTENSION_AUTH` to integrate SSO, LDAP, or MFA. Set `browser_extension.authenticator` to your service id.

## Build and install

See `extension/README.md` and `extension/chrome/README.md` for building, loading unpacked extensions, and syncing static assets (`make extension-sync` from the bundle root).

## Security checklist

- [ ] `cors_allowed_origins` restricted (no `*` in production)
- [ ] HTTPS only
- [ ] `login_rate_limit` enabled with sensible thresholds
- [ ] Cron job for `nowo:vault:extension-tokens:purge`
- [ ] Short `token_ttl` if your threat model requires it

See also [Security](SECURITY.md).
