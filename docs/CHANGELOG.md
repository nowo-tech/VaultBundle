# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-07-04

First stable release of **VaultBundle** — password and secrets vault for Symfony.

### Added

- Password and secrets vault: items, folders, grants, trash, password generator
- Item types: login, secure note, credit card, contact, identity documents, document attachments
- Sharing UI for items and folders (user/team grants with read, write, admin)
- `VaultTeamMembershipResolverInterface` for team membership
- Shared items list via `VaultGrant`
- Search by title in vault index
- Read-only mode via `VaultEvents::ITEM_READ_ONLY_RESOLVE`
- Document file attachments (encrypted in payload)
- Server-side libsodium payload encryption
- Symfony events for list queries, access checks, and grant picker (`VaultGrantListQueryEvent`)
- Symfony Flex recipe `.symfony/recipes/nowo-tech/vault-bundle/1.0.0`
- Demo Symfony 8 + FrankenPHP on port **8023**
- Docs: INSTALLATION, CONFIGURATION, USAGE, CONTRIBUTING, CHANGELOG, UPGRADING, RELEASE, SECURITY, ENGRAM, DEMO-FRANKENPHP, SPEC-DRIVEN-DEVELOPMENT, Access control examples

### Changed

- Replaced Yopass share/E2E scaffolding with vault domain model
- Documentation rewritten for vault use cases

[1.0.0]: https://github.com/nowo-tech/VaultBundle/releases/tag/v1.0.0
