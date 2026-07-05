# Nowo Vault browser extensions

Cross-browser WebExtensions for VaultBundle autofill.

| Browser | Folder | Install |
|---------|--------|---------|
| Chrome / Brave | `extension/chrome/` | Load unpacked in `chrome://extensions` |
| Firefox | `extension/firefox/` | Temporary add-on in `about:debugging` |

## Development

Source code lives in TypeScript:

- `extension/src/` — TypeScript modules (API, autofill, popup, background)
- `extension/static/` — HTML and CSS copied unchanged into browser folders

Build and sync to Chrome/Firefox:

```bash
pnpm run build:extension   # compiles TS → extension/build/
make extension-sync        # build + copy to chrome/ and firefox/
```

Do not edit generated `.js` files in `chrome/` or `firefox/` — they are overwritten on sync.

Run extension unit tests:

```bash
pnpm test
```

## Backend setup

See `extension/chrome/README.md` for YAML, security, and migration steps.
