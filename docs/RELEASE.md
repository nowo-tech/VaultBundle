# Release checklist

Use this checklist when cutting a new version. The workflow [.github/workflows/release.yml](../.github/workflows/release.yml) runs on push of a tag `v*` and creates the GitHub Release with body from the tag message and the matching changelog section.

## Before tagging

1. **CHANGELOG.md**
   - Move [Unreleased] entries to a new version section: `## [X.Y.Z] - YYYY-MM-DD` (e.g. `## [1.0.0] - 2026-03-11`).
   - Keep an empty `## [Unreleased]` at the top for future changes.

2. **UPGRADING.md**
   - Add or update upgrade notes for the new version if there are breaking or notable changes.

3. **Run release-check**
   - From the bundle root: `make release-check` (validates composer, runs cs-fix, cs-check, rector-dry, phpstan, test-coverage, test-ts, and demo `release-verify` HTTP smoke).

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

### Example for v1.1.0

After running `make release-check` and committing all changes (CHANGELOG, UPGRADING, docs, and any CS/test fixes):

```bash
git checkout main
git pull origin main
git tag -a v1.1.0 -m "Release v1.1.0"
git push origin v1.1.0
```

### Example for v1.0.0

After running `make release-check` and committing all changes (CHANGELOG, UPGRADING, docs, and any CS/test fixes):

```bash
git checkout main
git pull origin main
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```
