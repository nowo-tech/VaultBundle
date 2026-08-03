# Release checklist

Use this checklist when cutting a new version. The workflow [.github/workflows/release.yml](../.github/workflows/release.yml) runs on push of a tag `v*` and creates the GitHub Release with body from the tag message and the matching changelog section.

Current stable target: **v1.3.0**.

## Before tagging

1. **CHANGELOG.md**
   - Move [Unreleased] entries to a new version section: `## [X.Y.Z] - YYYY-MM-DD`.
   - Keep an empty `## [Unreleased]` at the top for future changes.

2. **UPGRADING.md**
   - Add or update upgrade notes for the new version if there are breaking or notable changes.

3. **Run QA**
   - From the bundle root: `make release-check`.

4. **Commit**
   - Commit `docs/CHANGELOG.md`, `docs/UPGRADING.md` and any other release-related changes.
   - Push to `main` (or merge your release branch).

## Tag and push

```bash
git checkout main
git pull origin main
make check-no-cursor-coauthor
git tag -a v1.3.0 -m "Release v1.3.0 - REQ-UI-002 allow_unauthenticated / AllowAll"
git push origin main
git push origin v1.3.0
```

- Tag format must be **`vX.Y.Z`** so the workflow and Packagist recognize it.
- After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001).

### v1.3.0 (2026-08-03)

Highlights: REQ-UI-002 `security.allow_unauthenticated`, AllowAll checker, SecurityBundle guard, soft manage UI gate.

### v1.2.0 (2026-07-30)

Highlights: root `css_framework` + `vault/base.html.twig` with `parent()` asset stacking (REQ-UI-001).
