# Nowo Vault browser extension (Firefox)

WebExtension (Manifest V3) for Firefox. TypeScript source lives in `extension/src/`; run `make extension-sync` after editing source files.

## Install (temporary)

1. Configure the Symfony app as described in `extension/chrome/README.md` (same API endpoints).

2. Sync extension assets:

```bash
make extension-sync
```

3. Open `about:debugging` → **This Firefox** → **Load Temporary Add-on…** → select `extension/firefox/manifest.json`.

4. Open extension **Options** and set the vault base URL (e.g. `http://localhost:8023`).

5. Sign in from the popup with your app credentials.

## Notes

- Uses the same TypeScript build as Chrome/Brave (`runtime.ts` picks `browser` or `chrome` API).
- Permanent install requires signing through Mozilla Add-ons (AMO) or enterprise policy.
- Brave and Chrome: use `extension/chrome/` (see `extension/chrome/README.md`).
