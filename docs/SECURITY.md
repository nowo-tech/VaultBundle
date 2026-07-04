# Security

## Threat model

| Asset | Risk | Mitigation |
|-------|------|------------|
| Vault payloads | Disclosure at rest | libsodium secretbox (`encryption_key`); DB stores ciphertext only |
| Master key | Server compromise | Store `VAULT_ENCRYPTION_KEY` in secrets manager; rotate with re-encryption plan |
| Manage routes | Unauthorized access | Symfony firewall + `VaultAccessCheckerInterface` |
| Item/folder ACL | IDOR | Creator ownership + `VaultGrant` + events |
| Attachments | Oversized uploads | `max_attachment_bytes` limit |
| Team grants | Wrong team access | Implement `VaultTeamMembershipResolverInterface` carefully |

## Access control

Default: **creator-only**. Extend via:

- `VaultGrant` (user/team + read/write/admin)
- `VaultEvents::ITEM_ACCESS_CHECK`, `FOLDER_ACCESS_CHECK`
- `VaultEvents::ITEM_READ_ONLY_RESOLVE` for view-only mode

## Secrets

- Never commit `VAULT_ENCRYPTION_KEY` or production `.env`
- Demo `.env.example` uses a placeholder key for local use only

## Release checklist

- [ ] Unique encryption key per environment
- [ ] HTTPS in production
- [ ] Access checker configured for your roles
- [ ] Team resolver reviewed if using team grants
- [ ] Attachment size limit appropriate for your storage

See [examples/AccessControl.md](examples/AccessControl.md).
