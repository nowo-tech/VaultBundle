# Release checklist

Use this checklist when cutting a new version. The workflow [.github/workflows/release.yml](../.github/workflows/release.yml) runs on push of a tag `v*` and creates the GitHub Release with body from the tag message and the matching changelog section.

## Before tagging

1. **CHANGELOG.md**
   - Move [Unreleased] entries to a new version section: `## [X.Y.Z] - YYYY-MM-DD` (e.g. `## [1.0.0] - 2026-03-11`).
   - Keep an empty `## [Unreleased]` at the top for future changes.

2. **UPGRADING.md**
   - Add or update upgrade notes for the new version if there are breaking or notable changes.

3. **Run QA**
   - From the bundle root: `composer validate --strict`, `composer cs-check`, `composer phpstan`, `composer test` (and `composer test-coverage` on the reference matrix: PHP 8.2 + Symfony 7.0).

4. **Commit**
   - Commit `docs/CHANGELOG.md`, `docs/UPGRADING.md` and any other release-related changes.
   - Push to `main` (or merge your release branch).

## Tag and push

Replace `X.Y.Z` with the version (e.g. `1.0.0`):

```bash
git checkout main
git pull origin main
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

- Tag format must be **`vX.Y.Z`** (e.g. `v1.0.0`) so the workflow and Packagist recognize it.
- After the push, GitHub Actions creates the release and appends the changelog entry for that version to the release body.
- Packagist will pick up the new tag automatically.

### v1.1.4 (2026-07-22)

Highlights: demo `FRANKENPHP_MODE` (classic/worker), CS Fixer `import_symbols`, dependency bumps (doctrine-encrypt-bundle 2.3, Vite, Rector, PHP-CS-Fixer, actions/checkout@v7).

After running QA and committing all changes:

```bash
git checkout main
git pull origin main
make check-no-cursor-coauthor
git tag -a v1.1.4 -m "Release v1.1.4"
git push origin main
git push origin v1.1.4
```

### v1.1.3 (2026-07-16)

Highlights: Contributor Covenant Code of Conduct, git hooks / CI for REQ-GIT-001 (no Cursor co-author trailers), GITHUB_CI docs.

After running QA and committing all changes:

```bash
git checkout main
git pull origin main
make check-no-cursor-coauthor
git tag -a v1.1.3 -m "Release v1.1.3"
git push origin main
git push origin v1.1.3
```

### v1.1.2 (2026-07-13)

Highlights: CI fixes for PHP 8.4/8.5 × Symfony 8.0/8.1 (Doctrine Bundle 3 test kernel), lazy-proxy test deps, kernel shutdown in E2E suites.

After running QA and committing all changes:

```bash
git checkout main
git pull origin main
git tag -a v1.1.2 -m "Release v1.1.2"
git push origin main
git push origin v1.1.2
```

### v1.1.1 (2026-07-08)

Highlights: GitHub Spec Kit baseline, SPEC-KIT manual, demo Packagist deps for optional bundles.

After running `make release-check` and committing all changes:

```bash
git checkout main
git pull origin main
git tag -a v1.1.1 -m "Release v1.1.1"
git push origin main
git push origin v1.1.1
```

### v1.1.0 (2026-07-05)

Highlights: browser extension API, tags, runtime config, key rotation command, manage CSRF, extension rate limiting.

After running `make release-check` and committing all changes:

```bash
git checkout main
git pull origin main
git tag -a v1.1.0 -m "Release v1.1.0"
git push origin v1.1.0
```

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.

## Example for v1.0.0

After running `make release-check` and committing all changes (CHANGELOG, UPGRADING, docs, and any CS/test fixes):

```bash
git checkout main
git pull origin main
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```
