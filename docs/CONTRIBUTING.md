# Contributing

Thank you for contributing to Vault Bundle.


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
## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
