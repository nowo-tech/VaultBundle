# Nowo Vault browser extension (Chrome / Brave)

Manifest V3 extension that connects to VaultBundle browser-extension API endpoints.

## Install (unpacked)

1. Enable the API in your app:

```yaml
# config/packages/nowo_vault.yaml
nowo_vault:
    browser_extension:
        enabled: true
        user_provider: security.user.provider.concrete.app_user_provider
        cors_allowed_origins: ['*']   # development only
```

2. Allow public access to extension routes in `security.yaml`:

```yaml
access_control:
    - { path: ^/api/vault/extension, roles: PUBLIC_ACCESS }
```

3. Run Doctrine schema update / migration for `{table_prefix}_extension_tokens`.

4. Open `chrome://extensions` (or `brave://extensions`), enable **Developer mode**, click **Load unpacked**, select this folder:

`extension/chrome/`

5. Open extension **Options** and set the vault base URL (e.g. `http://localhost:8023`).

6. Sign in from the extension popup with your app credentials.

## Behaviour

- On pages with a password field, the extension queries `GET /api/vault/extension/logins?domain=…`.
- Matching uses login item `websites[]` with subdomain-aware scoring (most specific match first).
- Click a suggested login to autofill username/password fields.

## Custom authentication

Implement `VaultBrowserExtensionAuthenticatorInterface` or listen to `VaultEvents::BROWSER_EXTENSION_AUTH`.

## Firefox and other browsers

Firefox: `extension/firefox/` (see `extension/firefox/README.md`). Run `make extension-sync` after editing `extension/src/`.
