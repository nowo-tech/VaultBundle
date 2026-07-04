# Contributing

Thank you for contributing to Vault Bundle.

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
