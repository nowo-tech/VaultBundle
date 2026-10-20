# Spec-driven development — VaultBundle

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — what **VaultBundle** guarantees to applications that integrate it (see [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INSTALLATION.md`](INSTALLATION.md)). **PHPUnit** and **PHPStan** (and **Vitest** when applicable) enforce contracts in CI where applicable.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles and demos (when present) so changes to scripts, ports, and demo workflows stay discoverable from issues and PRs.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

---

## Table of contents

- [Product vision](#product-vision)
- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [REQ traceability](#req-traceability)
- [Validation](#validation)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [Relationship to Engram](#relationship-to-engram)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)

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
| US-06 | As a browser-extension user, I authenticate and receive matching logins for the current site domain. |

## Functional scope

**Goal:** a personal and team password vault for Symfony applications (structured secrets, folders, sharing, trash, password generator, browser-extension API), with payloads encrypted at rest.

**In scope:** vault entities and repositories, payload encryption (Sodium / runtime key) and re-encryption, manage Web UI (UiKit), sharing with users and teams, trash lifecycle, tags, password generator (server + client), runtime configuration stored in the database, browser-extension Bearer-token API, domain events for host extension points, console commands (token purge, re-encryption).

**Explicit non-goals:**

- User management, authentication and SSO: the host application owns users, firewalls and roles (`security.*` options only map to the host).
- Key management / HSM: key rotation is an operational procedure ([ENCRYPTION-KEY-ROTATION.md](ENCRYPTION-KEY-ROTATION.md)), not an automatic feature of the runtime settings form.
- Browser-extension client behavior: the bundle owns the extension API ([BROWSER-EXTENSION.md](BROWSER-EXTENSION.md)); the reference Chrome/Firefox client under `extension/` is a separate deliverable, not loaded by the Symfony bundle and outside the `src/` inventory.
- `demo/` trees are illustrative only and not part of the Packagist API.

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

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR: acceptance criteria for the **product** and, if relevant, **Makefiles/demos** (`REQ-*`).
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes: add or adjust `REQ-*` comments and the requirement table.
4. **Ship integrator docs** when behavior or configuration changes: [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`CHANGELOG.md`](CHANGELOG.md), and [`UPGRADING.md`](UPGRADING.md) when consumers must change code or config.
5. **Keep Spec Kit artifacts in sync** when production code under `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).
   - For **new features**, use Cursor Agent skills (`/speckit-specify`, `/speckit-plan`, `/speckit-tasks`) as documented in SPEC-KIT.

---

## Relationship to Engram

[`ENGRAM.md`](ENGRAM.md) covers the org-wide checklist and assistant memory (MCP); it points back here for local `REQ-*` traceability. This document is the functional contract of the bundle and links to Engram instead of duplicating checklist prose.

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
# Install Specify CLI (once per machine) — see SPEC-KIT.md
specify init --here --force --integration cursor-agent --script sh
specify integration list   # Cursor → installed (default)
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For day-to-day tooling details, skills reference, folder layout, and troubleshooting, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

