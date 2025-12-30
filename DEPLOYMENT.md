# Deployment Setup Guide

This repository includes GitHub Actions workflows for automated deployment to your server.

## Available Deployment Methods

### 1. SSH/rsync Deployment (Recommended)
Uses SSH and rsync for secure, efficient file transfers.

**File:** `.github/workflows/deploy.yml`

**Required GitHub Secrets:**
- `SSH_PRIVATE_KEY` - Your private SSH key (the entire key, including `-----BEGIN` and `-----END` lines)
- `SERVER_HOST` - Your server hostname or IP address (e.g., `example.com` or `192.168.1.100`)
- `SERVER_USER` - SSH username (e.g., `root` or `deploy`)
- `SERVER_PATH` - Full path to plugin directory on server (e.g., `/var/www/html/wp-content/plugins/vernal-contentum/`)

**Setup Steps:**
1. Generate an SSH key pair if you don't have one:
   ```bash
   ssh-keygen -t ed25519 -C "github-actions-deploy"
   ```
2. Copy the public key to your server:
   ```bash
   ssh-copy-id -i ~/.ssh/id_ed25519.pub user@your-server.com
   ```
3. Add the private key to GitHub Secrets:
   - Go to your repository → Settings → Secrets and variables → Actions
   - Click "New repository secret"
   - Name: `SSH_PRIVATE_KEY`
   - Value: Contents of your private key file (`~/.ssh/id_ed25519`)
4. Add the other secrets:
   - `SERVER_HOST`: Your server address
   - `SERVER_USER`: Your SSH username
   - `SERVER_PATH`: Full path to plugin directory

### 2. FTP Deployment
Uses FTP for file transfer (less secure, but works if SSH isn't available).

**File:** `.github/workflows/deploy-ftp.yml`

**Required GitHub Secrets:**
- `SERVER` - FTP server address (e.g., `ftp.example.com`)
- `USERNAME` - FTP username
- `PASSWORD` - FTP password

**Note:** The workflow uses the FTP user's default directory (configured on your server). No path needs to be specified.

**Setup Steps:**
1. Go to your repository → Settings → Secrets and variables → Actions
2. Add the three FTP secrets listed above

## Enabling a Workflow

1. **For SSH/rsync:** The `deploy.yml` workflow is ready to use. Just add the secrets.
2. **For FTP:** Rename `deploy-ftp.yml` to `deploy.yml` or disable the SSH one and enable this one.

To disable a workflow, you can:
- Delete the workflow file, or
- Comment out the `on:` trigger section

## Deployment Triggers

Deployments will automatically run when:
- You push to the `main` or `master` branch
- You manually trigger it from the Actions tab (workflow_dispatch)

## Testing the Deployment

1. Make a small change to a file
2. Commit and push to `main` branch
3. Go to the Actions tab in GitHub to see the deployment progress
4. Check your server to verify files were updated

## Troubleshooting

### SSH Connection Issues
- Verify your SSH key is correctly formatted in GitHub Secrets
- Ensure the public key is in `~/.ssh/authorized_keys` on the server
- Test SSH connection manually: `ssh user@server.com`

### Permission Issues
- Ensure the server user has write permissions to the target directory
- Check directory ownership: `ls -la /path/to/plugin`

### FTP Issues
- Verify FTP credentials are correct
- Check if passive mode is required (may need to modify workflow)
- Ensure the FTP user has write permissions to their default directory

## Security Notes

- Never commit secrets or credentials to the repository
- Use GitHub Secrets for all sensitive information
- Regularly rotate SSH keys and passwords
- Consider using a dedicated deployment user with limited permissions

