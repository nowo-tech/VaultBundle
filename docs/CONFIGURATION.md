# Configuration

All options under `nowo_vault` in `config/packages/nowo_vault.yaml`.

## Required

| Option | Description |
|--------|-------------|
| `user_class` | FQCN of User entity (`UserInterface` + `getId()`) |
| `encryption_key` | Base64-encoded 32-byte libsodium key for payload encryption |

Generate a key:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

## Database

| Option | Default | Description |
|--------|---------|-------------|
| `table_prefix` | `vault_` | Prefix for `vault_items`, `vault_folders`, `vault_grants` |
| `database.driver` | `doctrine_orm` | Persistence driver |
| `database.platform` | `postgresql` | Documented platform |
| `database.entity_manager` | `default` | Doctrine ORM entity manager |

## Security

| Option | Default | Description |
|--------|---------|-------------|
| `security.access_checker` | null | Custom `VaultAccessCheckerInterface` service id |
| `security.admin_roles` | `[ROLE_ADMIN]` | Bypass feature checks |
| `security.access_roles` | `[ROLE_USER]` | Open manage UI |
| `security.create_roles` | `[ROLE_USER]` | Create items/folders |
| `security.list_roles` | `[ROLE_USER]` | List vault |
| `security.delete_roles` | `[ROLE_USER]` | Trash / purge / share admin |

## Teams

| Option | Default | Description |
|--------|---------|-------------|
| `team_membership_resolver` | null | Service implementing `VaultTeamMembershipResolverInterface`; null = no teams |

## Attachments

| Option | Default | Description |
|--------|---------|-------------|
| `max_attachment_bytes` | `512000` | Max document attachment size (stored encrypted in payload) |

## Routes

All routes are configurable under `nowo_vault.routes` (see `Configuration.php` defaults). Loader type: `nowo_vault`.

## Templates

| Key | Default |
|-----|---------|
| `layout` | `@NowoVaultBundle/layout.html.twig` |
| `home` | `@NowoVaultBundle/vault/home.html.twig` |
| `items` | `@NowoVaultBundle/vault/items.html.twig` |
| `index` | `@NowoVaultBundle/vault/home.html.twig` (deprecated alias of `home`) |
| `item_form` | `@NowoVaultBundle/vault/item_form.html.twig` |
| `trash` | `@NowoVaultBundle/vault/trash.html.twig` |
| `shared` | `@NowoVaultBundle/vault/shared.html.twig` |
| `share` | `@NowoVaultBundle/vault/share.html.twig` |

## Firewall

Document `nowo_vault.firewall` in your `security.yaml`. All manage routes require authentication.
