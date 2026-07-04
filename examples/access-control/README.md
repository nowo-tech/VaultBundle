# Example listeners for your application (not autoloaded by the bundle)

Copy the PHP classes from this directory into `src/Vault/` (or similar) and register them:

```yaml
# config/services.yaml
services:
    App\Vault\AccessControl\TeamShareListListener: ~
    App\Vault\AccessControl\TeamShareAccessListener: ~
    App\Vault\AccessControl\IndividualShareGrantListener: ~
    App\Vault\AccessControl\RoleBasedShareAccessListener: ~
```

See [docs/examples/AccessControl.md](../docs/examples/AccessControl.md) for event reference and integration notes.
