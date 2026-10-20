# Contributing

Thank you for contributing to Vault Bundle.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Quality checks](#quality-checks)
- [Pull requests](#pull-requests)
- [Documentation](#documentation)
- [Code style](#code-style)
- [License](#license)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)
- [Igor worker audit (REQ-CS-008)](#igor-worker-audit-req-cs-008)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

```bash
make up
make install
make assets
make test
make test-ts
```

## Quality checks

```bash
make qa              # cs-check, phpstan, tests
make release-check   # full pre-release pipeline
```

## Pull requests

1. Fork and branch from `main`.
2. Add or update tests for behaviour changes.
3. Run `make cs-fix` and `make test` before opening the PR.
4. Update `docs/CHANGELOG.md` under `[Unreleased]`.
5. Use the PR template and link related issues.

## Documentation

- User-facing changes: `README.md` and `docs/`.
- Breaking changes: `docs/UPGRADING.md` and a new section in `CHANGELOG.md`.

## Code style

- PHP: PSR-12 via PHP-CS-Fixer, PHPStan level from `phpstan.neon.dist`.
- TypeScript: ESLint + Prettier config in the repo root.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat → Submit with Ctrl + Enter. Enter inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running** — in your user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts the running agent turn as soon as the follow-up is sent.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.

## Igor worker audit (REQ-CS-008)

Run `make igor` (or `composer igor`) before release. Igor audits package `src/` for FrankenPHP worker-state issues. It is require-dev only.
