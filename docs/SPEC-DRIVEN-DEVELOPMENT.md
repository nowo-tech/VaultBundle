# Spec-driven development — VaultBundle

## Product vision

Personal and team password vault for Symfony apps: structured secrets, folders, sharing, trash, password generator.

## User stories

| ID | Story |
|----|-------|
| US-01 | As a user, I store logins, notes, cards, contacts, and documents in my vault. |
| US-02 | As a user, I organize items in folders and recover deleted items from trash. |
| US-03 | As a creator, I share items or folders with other users or teams. |
| US-04 | As a user, I generate strong passwords from the manage UI. |
| US-05 | As an integrator, I extend list queries and ACL via Symfony events. |

## REQ traceability

| REQ | Makefile / demo |
|-----|-----------------|
| REQ-TEST-001 | `make test`, `composer test` |
| REQ-TEST-009 | `make test-ts` |
| REQ-DEMO-005 | `demo/symfony8/Makefile` → port **8023** |
| REQ-DEMO-007 | `demo/symfony8` target `update-bundle` |

## Validation

- PHPUnit: cryptographer, password generator, configuration
- Vitest: password generator client
- Demo manual: create login → trash → restore → generate password
