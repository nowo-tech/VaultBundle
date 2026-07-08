# Vault Bundle

[![CI](https://github.com/nowo-tech/VaultBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/VaultBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/vault-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/vault-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/vault-bundle.svg)](https://packagist.org/packages/nowo-tech/vault-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%2B%20%7C%208.0%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/VaultBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/VaultBundle)

> ⭐ **Found this useful?** Give it a **star** on [GitHub](https://github.com/nowo-tech/VaultBundle) so more developers can find it.

Symfony bundle for a **password and secrets vault**: logins, secure notes, credit cards, contacts, identity documents, folders, sharing, trash, read-only events, and a built-in password generator.

**FrankenPHP worker mode:** Supported — demo uses FrankenPHP on Symfony 8 ([Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)).

## Features

- Item types: login, secure note, credit card, contact, ID card, driver's license, passport, document
- Folders with share and trash; item soft-delete with restore/purge
- **Tags** — label items, filter and search by tag
- `VaultGrant` for users and teams (read / write / admin) + share UI
- `VaultTeamMembershipResolverInterface` for team-based grants
- **Browser extension** — REST API + Chrome/Firefox autofill (optional)
- **Runtime config** — optional DB-backed settings with admin UI
- **Key rotation** — `nowo:vault:reencrypt` command and demo walkthrough
- Events: list query/result, access check, read-only resolve, extension auth
- Server-side libsodium payload encryption; CSRF on manage POST actions
- Dark manage UI + password generator (modal + inline)
- Document attachments (encrypted in payload)

## Installation

```bash
composer require nowo-tech/vault-bundle
```

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

```yaml
# config/packages/nowo_vault.yaml
nowo_vault:
    user_class: App\Entity\User
    encryption_key: '%env(VAULT_ENCRYPTION_KEY)%'
```

See [Installation](docs/INSTALLATION.md).

## Demo

```bash
make -C demo/symfony8 up
# Demo started at: http://localhost:8023  →  /tools/vault (auto-login)
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Browser extension](docs/BROWSER-EXTENSION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Encryption key rotation](docs/ENCRYPTION-KEY-ROTATION.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [Access control & read-only events](docs/examples/AccessControl.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)

## Tests and coverage

```bash
make test              # PHPUnit
make test-ts           # Vitest
make test-coverage     # PHP coverage report
make release-check     # Full pre-release QA
```

The `test-coverage-100` / `release-check` gate targets **100% line coverage on the measured subset of `src/`** (see `phpunit.xml.dist`). Controllers, repositories, forms, and Doctrine listeners are **excluded** from that metric; they are covered by E2E and integration tests instead. New services and domain logic under `src/Service/`, `src/Security/`, etc. must remain fully covered to pass release checks.

Run `make test-coverage` and `make test-ts` for current PHP and TS coverage percentages.

## License

MIT — see [LICENSE](LICENSE).
