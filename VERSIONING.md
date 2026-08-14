# Versioning & Release Guide

This plugin uses GitHub Releases (with a `vernal-contentum.zip` asset) for WordPress in-admin updates via `plugin-update-checker`.

## Automatic release (canonical)

**Do not rely on clicking Actions → workflow_dispatch.** Ship path:

1. Bump version in `vernal-contentum.php` (`Version:` header + `VERNAL_CONTENTUM_VERSION`).
2. Commit and `git push origin main` (SSH deploy workflow syncs the server).
3. Create and push the tag matching that version:

```bash
git tag v1.2.2
git push origin v1.2.2
```

4. Workflow **Build & Release Plugin** (`.github/workflows/release.yml`) runs on `push` of tags `v*`:
   - Builds `vernal-contentum.zip` from the **tagged commit**
   - Creates/updates the GitHub Release
   - Uploads the ZIP (required — source zipballs are not enough for the update checker)

Agents shipping plugin changes must complete step 3 in the same session. Pushing `main` alone does **not** publish a Release.

## Optional manual rebuild

`workflow_dispatch` remains available to rebuild a release from the current `Version:` header (or an explicit version input). Prefer the tag-push path.

## Update checker

- Polls GitHub Releases about every **1 hour** (also refreshes when an admin opens Plugins, at most hourly)
- Requires release asset named `vernal-contentum.zip`
- ZIP root folder must be `vernal-contentum/`
- Private repos need `VERNAL_GITHUB_TOKEN` in `wp-config.php` (Contents: Read)

## Plugin slug

`vernal-contentum` — must match folder name in the ZIP, update-checker slug, and WP install path.
