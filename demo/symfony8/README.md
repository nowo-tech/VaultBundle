# Vault Bundle — Demo

Symfony 8 demo with FrankenPHP and auto-login.

```bash
make up
# Demo started at: http://localhost:8023
```

Open `/tools/vault` to manage vault items, folders, sharing, and trash.

Environment: copy `.env.example` to `.env` and set `VAULT_ENCRYPTION_KEY`.

## Maintenance commands

The demo enables database-backed runtime config (`config_storage.enabled: true`). Bundle console commands:

```bash
# Purge expired browser extension Bearer tokens
make vault-purge-tokens

# Key rotation — always dry-run first
export VAULT_OLD_KEY='...'   # current key (see .env VAULT_ENCRYPTION_KEY)
export VAULT_NEW_KEY='...'   # php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'

make vault-reencrypt-dry-run
make vault-reencrypt              # re-crypt payloads only
make vault-reencrypt-persist      # re-crypt + store new key in vault_settings
make vault-rotation-demo          # full automated walkthrough
make vault-rotation-demo ARGS="--fresh --update-env"
```

After `vault-reencrypt` without `--persist-new-key`, update `VAULT_ENCRYPTION_KEY` in `.env` and restart the container.

Scripts:

| File | Purpose |
|------|---------|
| `scripts/vault-key-rotation-demo.sh` | End-to-end rotation (seed → dry-run → reencrypt → verify) |
| `app:vault-demo:count` | Print vault item count |
| `app:vault-demo:verify` | Decrypt-check all vault items |
| `app:vault-demo:seed` | Create demo logins when DB has user but no items |

See [Encryption key rotation](../../docs/ENCRYPTION-KEY-ROTATION.md) for the full procedure.
