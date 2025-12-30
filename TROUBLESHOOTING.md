# Troubleshooting GitHub Actions Deployment

## Common Issues and Solutions

### 1. Workflow Failed - Check These First

#### Missing Secrets
**Error**: "Secret not found" or authentication failures

**Solution**:
1. Go to your GitHub repository
2. Settings → Secrets and variables → Actions
3. Verify these secrets exist:
   - `SERVER` - Your FTP server address (e.g., `ftp.example.com` or `192.168.1.100`)
   - `USERNAME` - Your FTP username
   - `PASSWORD` - Your FTP password

**Test**: The workflow now includes a step to verify secrets are set before attempting deployment.

#### FTP Connection Issues
**Error**: "Connection timeout" or "Could not connect"

**Possible Causes**:
- Firewall blocking GitHub Actions IPs
- FTP server not accessible from internet
- Wrong server address
- FTP port (21) blocked

**Solutions**:
- Whitelist GitHub Actions IP ranges (if possible)
- Verify FTP server is publicly accessible
- Check server address is correct (try connecting with an FTP client)
- Ensure FTP port 21 is open

#### Authentication Failures
**Error**: "Login incorrect" or "Authentication failed"

**Solutions**:
- Double-check username and password in GitHub Secrets
- Ensure credentials haven't expired
- Verify the FTP user has proper permissions
- Try connecting manually with an FTP client to test credentials

#### Server Directory Issues
**Error**: "Cannot change directory" or "Permission denied"

**Solutions**:
- Verify the FTP user's default directory is correct
- Ensure the FTP user has write permissions
- Check if the directory exists on the server
- If needed, specify a different path in the workflow (currently set to `/`)

### 2. Viewing Error Logs

1. Go to your GitHub repository
2. Click the **Actions** tab
3. Find the failed workflow run (look for the red X)
4. Click on the failed run
5. Click on the failed job (usually "deploy")
6. Expand the failed step to see detailed error messages

### 3. Testing FTP Connection Manually

Before troubleshooting the GitHub Action, test your FTP connection:

**Using command line (if you have `ftp` or `lftp` installed)**:
```bash
ftp your-server.com
# Enter username and password when prompted
```

**Using an FTP client** (FileZilla, Cyberduck, etc.):
- Try connecting with the same credentials
- Verify you can upload files
- Check the default directory path

### 4. Workflow-Specific Issues

#### The workflow uses `server-dir: /`
This means it uses the FTP user's default/home directory. If your plugin needs to be in a specific subdirectory, you may need to:

1. Change the FTP user's home directory on your server, OR
2. Modify the workflow to use a specific path:
   ```yaml
   server-dir: /wp-content/plugins/vernal-contentum/
   ```

#### Excluded Files
The workflow excludes:
- `.git*` files
- `.github/` directory
- Documentation files (README.md, DEPLOYMENT.md, VERSIONING.md)
- `.DS_Store` files

If you need to deploy these files, modify the `exclude` section in the workflow.

### 5. Debug Mode

The workflow now includes `log-level: verbose` which will show more detailed information about what's being uploaded.

### 6. Manual Testing

You can manually trigger the workflow:
1. Go to Actions tab
2. Select "Deploy to Server (FTP)" workflow
3. Click "Run workflow"
4. Select the branch (usually `main`)
5. Click "Run workflow" button

This helps test without making a new commit.

### 7. Still Having Issues?

If you're still experiencing problems:
1. Check the GitHub Actions logs for the specific error message
2. Verify your FTP server is accessible from the internet
3. Test FTP credentials manually
4. Check server logs (if you have access)
5. Consider using the SSH/rsync deployment method instead (see `DEPLOYMENT.md`)

