# Encryption key rotation

Vault item payloads are encrypted with libsodium secretbox using `nowo_vault.encryption_key` (YAML/env bootstrap, optional encrypted DB override). Rotating the key requires **re-encrypting every stored payload** with the new key.

## Before you start

1. Take a full database backup.
2. Put the application in maintenance mode or stop writers.
3. Generate a new key:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

4. Keep the **old key** available until re-encryption completes.

## Procedure

### 1. Re-encrypt payloads

Use the bundled console command (maintenance window recommended):

```bash
# Verify every payload decrypts with the old key (no writes)
# Pass --new-key when the effective config key still equals --old-key (e.g. demo dry-run)
php bin/console nowo:vault:reencrypt --old-key="$OLD_KEY" --new-key="$NEW_KEY" --dry-run

# Re-encrypt to a new key already configured in nowo_vault.encryption_key
php bin/console nowo:vault:reencrypt --old-key="$OLD_KEY"

# Re-encrypt to an explicit new key before updating YAML/DB
php bin/console nowo:vault:reencrypt --old-key="$OLD_KEY" --new-key="$NEW_KEY"

# When config_storage.enabled, persist the new key after success
php bin/console nowo:vault:reencrypt --old-key="$OLD_KEY" --new-key="$NEW_KEY" --persist-new-key --force --no-interaction
```

Demo (Docker):

```bash
export VAULT_OLD_KEY='...'
export VAULT_NEW_KEY='...'
make -C demo/symfony8 vault-reencrypt-dry-run
make -C demo/symfony8 vault-reencrypt
make -C demo/symfony8 vault-reencrypt-persist   # includes --persist-new-key

# Full automated walkthrough (seed → dry-run → reencrypt → verify)
make -C demo/symfony8 vault-rotation-demo
```

Options:

| Option | Description |
|--------|-------------|
| `--old-key` | Required. Previous base64 libsodium key. |
| `--new-key` | Target key. Defaults to effective `nowo_vault.encryption_key`. |
| `--batch-size` | Items per flush (default `50`). |
| `--dry-run` | Decrypt-check only. |
| `--skip-trash` | Skip soft-deleted items. |
| `--persist-new-key` | Store `--new-key` via `VaultRuntimeConfigWriter` (requires DB runtime config). |
| `--force` | Skip confirmation; **required** for non-interactive writes (CI, cron, `make vault-reencrypt`). |

### 2. Custom script (optional)

For advanced workflows you can still inject `VaultPayloadReencryptionService` or replicate the batch loop manually:

```php
use Nowo\VaultBundle\Entity\VaultItem;
use Nowo\VaultBundle\Repository\VaultItemRepositoryInterface;
use Nowo\VaultBundle\Security\SodiumVaultPayloadCryptographer;
use Nowo\VaultBundle\Security\VaultPayloadCryptographerInterface;

final readonly class VaultReencryptionRunner
{
    public function __construct(
        private VaultItemRepositoryInterface $items,
        private VaultPayloadCryptographerInterface $currentCryptographer,
        private string $oldKeyBase64,
    ) {
    }

    public function run(int $batchSize = 100): int
    {
        $oldCryptographer = new SodiumVaultPayloadCryptographer($this->oldKeyBase64);
        $rotated          = 0;

        foreach ($this->items->findByCreator(/* admin or system user */) as $item) {
            $plaintext = $oldCryptographer->decrypt($item->getCiphertext());
            $item->setCiphertext($this->currentCryptographer->encrypt($plaintext));
            $this->items->save($item);
            ++$rotated;

            if ($rotated % $batchSize === 0) {
                // flush / clear entity manager if needed
            }
        }

        return $rotated;
    }
}
```

If you use database-backed runtime config (`config_storage.enabled`), update the stored key **only after** all payloads are re-encrypted, or point `encryption_key` at the new value in YAML and run the script with explicit old/new cryptographers as above.

### 3. Verify

- Spot-check decrypt via manage UI (view/edit items).
- Run your application test suite.
- Confirm extension login and `/logins` still return credentials.

### 4. Retire the old key

Remove the old key from secrets management after verification. Do not commit either key to git.

## Runtime config UI limitation

The manage UI **generates** an encryption key only when none exists; it does not overwrite an existing DB key. Rotation must be performed operationally (script + config writer), not via the runtime settings form alone.

Use `VaultRuntimeConfigWriter::update(['encryption_key' => $newKey])` only when re-encryption is complete.

## Related examples

- [Access control & events](examples/AccessControl.md)
- [Configuration — encryption_key](CONFIGURATION.md#required)
- [Security threat model](SECURITY.md)
