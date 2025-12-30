# Web App Integration Guide

## Overview

The WordPress plugin provides connection data in JSON format that needs to be pasted into the Vernal Contentum web app to establish the connection.

## Connection Data Format

The WordPress plugin outputs JSON with the following structure:

```json
{
  "site_url": "https://vernalcontentum.com",
  "api_key": "vc_bab6f67653b7eb32114952ad9fa76e4350bdfbae108e169e76533c72f45fef58",
  "api_endpoint": "https://vernalcontentum.com/wp-json/vernal-contentum/v1/"
}
```

## Web App Implementation Requirements

### 1. Single-Button Paste Functionality

**Location:** WordPress Connection card in Account Settings

**Requirements:**
- Add a "Paste Connection Data" button next to or above the input fields
- When clicked, it should:
  1. Read from clipboard (using Clipboard API)
  2. Parse the JSON data
  3. Populate the form fields automatically
  4. Show success/error feedback

**Implementation Example:**
```javascript
// Button HTML
<button id="paste-connection-data" class="btn-primary">
  Paste Connection Data
</button>

// JavaScript
document.getElementById('paste-connection-data').addEventListener('click', async () => {
  try {
    // Read from clipboard
    const text = await navigator.clipboard.readText();
    
    // Parse JSON
    const data = JSON.parse(text);
    
    // Populate fields
    document.getElementById('wordpress-site-url').value = data.site_url || '';
    document.getElementById('wordpress-username').value = data.username || ''; // If included
    document.getElementById('wordpress-app-password').value = data.api_key || '';
    
    // Show success message
    showNotification('Connection data pasted successfully!', 'success');
  } catch (error) {
    showNotification('Failed to paste. Please ensure you copied the connection data from WordPress.', 'error');
  }
});
```

### 2. Field Mapping

**WordPress Plugin Data → Web App Fields:**

| WordPress Plugin Field | Web App Field | Notes |
|------------------------|---------------|-------|
| `site_url` | WordPress Site URL | Direct mapping |
| `api_key` | App Password | The API key IS the app password |
| `api_endpoint` | (Hidden/Internal) | Store for API calls, not shown to user |
| `username` | Username | **NEED TO ADD** - See below |

### 3. Missing Field: Username

**Issue:** The WordPress plugin doesn't currently include the username in the connection data.

**Solution Options:**

**Option A:** Add username to WordPress plugin output (Recommended)
- Modify `class-vernal-settings.php` to include current WordPress username
- Update JSON output to include: `"username": "admin"` (or current user)

**Option B:** Make username optional in web app
- Allow user to manually enter username after pasting
- Or fetch it via API call after connection is established

**Option C:** Use API key as authentication (no username needed)
- If WordPress REST API accepts API key alone, username may not be required
- Verify if WordPress REST API needs username + app password or just app password

### 4. Data Storage

**Where to Store:**
- Store in your database (wherever WordPress credentials are currently stored)
- Fields to save:
  - `wordpress_site_url` → `site_url` from JSON
  - `wordpress_username` → `username` (if provided or entered)
  - `wordpress_app_password` → `api_key` from JSON
  - `wordpress_api_endpoint` → `api_endpoint` from JSON (for internal use)

**Database Schema Example:**
```sql
CREATE TABLE wordpress_connections (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT,
  site_url VARCHAR(255),
  username VARCHAR(100),
  app_password VARCHAR(255), -- Encrypted
  api_endpoint VARCHAR(255),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

### 5. Connection Flow

1. User copies connection data from WordPress plugin admin
2. User goes to web app → Account Settings → WordPress Connection
3. User clicks "Paste Connection Data" button
4. Web app reads clipboard, parses JSON, populates fields
5. User clicks "Connect to WordPress" (or auto-connect if all fields valid)
6. Web app saves data to database
7. Web app tests connection by calling WordPress API
8. Show success/error feedback

### 6. API Testing

**Test Endpoint:** After pasting, test the connection:

```javascript
async function testWordPressConnection() {
  const siteUrl = document.getElementById('wordpress-site-url').value;
  const apiKey = document.getElementById('wordpress-app-password').value;
  
  try {
    const response = await fetch(`${siteUrl}/wp-json/vernal-contentum/v1/categories`, {
      headers: {
        'X-API-Key': apiKey
      }
    });
    
    if (response.ok) {
      showNotification('Connection successful!', 'success');
      return true;
    } else {
      showNotification('Connection failed. Please check your credentials.', 'error');
      return false;
    }
  } catch (error) {
    showNotification('Connection error: ' + error.message, 'error');
    return false;
  }
}
```

## Questions for Clarification

1. **Username Field Purpose:**
   - Is the "Username" field in the web app for the WordPress admin username?
   - Or is it for something else (Vernal account username)?
   - Do we need username + app password, or just app password for WordPress REST API?

2. **Vernal Login Fields:**
   - The fields shown (Username: matt@envoydesign.com, Password: masked) - are these for:
     - Your Vernal Contentum account login?
     - Or WordPress credentials?
   - What API endpoints does the web app need to call to authenticate with Vernal?

3. **API Authentication:**
   - Does the WordPress REST API require:
     - Username + App Password (Basic Auth)?
     - Or just the API Key in header (X-API-Key)?
   - Current plugin uses X-API-Key header - is that sufficient?

4. **Connection Storage:**
   - Where are WordPress credentials currently stored in your database?
   - What table/collection name?
   - Do you want to store multiple WordPress connections per user, or just one?

## Next Steps

1. **Update WordPress Plugin** (if needed):
   - Add username to connection data JSON output
   - Or clarify if username is not needed

2. **Implement Web App Paste Functionality:**
   - Add paste button
   - Implement clipboard reading
   - Parse JSON and populate fields
   - Save to database

3. **Test Connection:**
   - Verify API key authentication works
   - Test all endpoints (sitemap, categories, authors, create post)

4. **Handle Errors:**
   - Invalid JSON format
   - Missing required fields
   - API connection failures
   - Authentication errors

