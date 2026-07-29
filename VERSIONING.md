# Versioning & Release Guide

This plugin uses automated versioning and packaging via GitHub Actions.

## How It Works

### 1. Automatic FTP Deployment
- **Trigger**: Every push to `main` or `master` branch
- **Action**: `.github/workflows/deploy-ftp.yml`
- **Result**: Files are automatically deployed to your server via FTP
- **Status**: ✅ Already configured and working

### 2. Automatic Version Packaging
- **Trigger**: When you create a Git tag starting with `v` (e.g., `v1.0.1`)
- **Action**: `.github/workflows/release.yml`
- **Result**: 
  - Creates a ZIP file of the plugin
  - Creates a GitHub Release
  - Uploads the ZIP as a release asset
  - WordPress will automatically detect updates

## Releasing a New Version

### Step 1: Update Version Number
Edit `vernal-contentum.php` and update:
```php
/**
 * Version: 1.0.1  // ← Update this
 */
define('VERNAL_CONTENTUM_VERSION', '1.0.1');  // ← Update this
```

### Step 2: Commit and Push
```bash
git add vernal-contentum.php
git commit -m "Bump version to 1.0.1"
git push origin main
```

### Step 3: Create a Tag and Push
```bash
git tag v1.0.1
git push origin v1.0.1
```

### Step 4: GitHub Action Runs Automatically
- The release workflow will:
  - Build a ZIP file
  - Create a GitHub Release
  - Upload the ZIP as an asset
  - WordPress sites with the plugin installed will see the update notification

## Update Checker

The plugin includes the `plugin-update-checker` library which:
- Checks GitHub Releases every 12 hours
- Shows update notifications in WordPress admin
- Allows one-click updates from the Plugins page
- Uses the ZIP file from GitHub Releases

## Plugin Slug

**Important**: The plugin slug is `vernal-contentum`. This must match:
- The folder name in the ZIP file
- The slug used in the update checker
- The folder name when installed in WordPress

## Current Setup

- ✅ FTP auto-deployment on push to main/master
- ✅ GitHub Releases for versioning
- ✅ Update checker integrated
- ✅ Automatic ZIP packaging on tag push

## Testing Updates

1. Install the plugin on a test WordPress site
2. Create a new version tag (e.g., `v1.0.2`)
3. Push the tag: `git push origin v1.0.2`
4. Wait a few minutes for the release to be created
5. In WordPress admin, go to Plugins → Check for updates
6. You should see the update notification

## Notes

- The GitHub repository must be **public**, or WordPress sites must define `VERNAL_GITHUB_TOKEN` in `wp-config.php` (fine-grained PAT with Contents: Read on this repo). Private repos return HTTP 404 to unauthenticated API calls, which breaks the update checker (`puc-github-http-error`).
- The plugin checks for updates every 12 hours automatically
- Users can manually check by clicking "Check for updates" on the Plugins page
- Only releases (not pre-releases) are shown as updates
- The ZIP file structure must have the plugin folder at the root level
- Release assets must include `vernal-contentum.zip` (folder slug `vernal-contentum`)

