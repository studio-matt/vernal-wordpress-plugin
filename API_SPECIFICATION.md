# WordPress Plugin API Specification

## ✅ CONFIRMED CAPABILITIES

### PROVIDES (GET Endpoints)

#### 1. Full Sitemap
**Endpoint:** `GET {api_endpoint}/sitemap`  
**Headers:** `X-API-Key: {api_key}`

**Returns comprehensive site data:**
- All published posts (with metadata: title, URL, author, categories, tags, featured image, etc.)
- All published pages
- All categories
- All tags  
- All authors
- Post types information

**Use Case:** Pass entire sitemap to LLM for content analysis and context.

#### 2. Posts-Only Data
**Available via:** Filter `response.posts` from sitemap endpoint

**Contains for each post:**
- `id`, `title`, `url`, `slug`
- `date`, `modified`
- `excerpt`
- `author` (display name), `author_id`
- `categories` (array of names)
- `tags` (array of names)
- `word_count`
- `featured_image` (URL)

**Use Case:** Get all posts for LLM to analyze content patterns, topics, style.

#### 3. Authors-Only Data
**Endpoint:** `GET {api_endpoint}/authors`  
**Headers:** `X-API-Key: {api_key}`

**Returns:**
- `id`, `username`, `display_name`
- `email`, `first_name`, `last_name`

**Use Case:** Populate author dropdown in web app for post creation.

**Alternative:** Also available in sitemap response as `response.authors` array.

#### 4. Categories List
**Endpoint:** `GET {api_endpoint}/categories`  
**Headers:** `X-API-Key: {api_key}`

**Returns:**
- `id`, `name`, `slug`
- `description`, `count`, `parent`

**Use Case:** Populate category dropdown in web app for post creation.

---

### ACCEPTS (POST Endpoint)

#### Create Post
**Endpoint:** `POST {api_endpoint}/posts`  
**Headers:** 
- `X-API-Key: {api_key}`
- `Content-Type: application/json`

**Required Fields:**
- `title` (string) - Post title
- `content` (string) - Post body/content (HTML supported)

**Optional Fields:**
- `status` (string) - `draft` | `publish` | `pending` (default: `draft`)
- `author_id` (integer) - WordPress user ID
- `category_id` (integer) - Single category ID
- `category_ids` (array) - Multiple category IDs: `[5, 10]`
- `featured_image_url` (string) - URL to image (will be downloaded and set as featured image)
- `excerpt` (string) - Post excerpt
- `tags` (array) - Tag names: `["tag1", "tag2"]`
- `post_date` (string) - Publication date: `"2024-12-30 12:00:00"`
- `meta` (object) - Custom fields: `{"key": "value"}`

**Request Example:**
```json
{
  "title": "My New Post Title",
  "content": "<p>Post content with HTML...</p>",
  "status": "draft",
  "author_id": 1,
  "category_id": 5,
  "featured_image_url": "https://example.com/image.jpg",
  "excerpt": "Post excerpt text",
  "tags": ["tag1", "tag2"]
}
```

**Success Response:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "title": "My New Post Title",
    "status": "draft",
    "url": "https://site.com/my-new-post/",
    "edit_url": "https://site.com/wp-admin/post.php?action=edit&post=123"
  }
}
```

**Error Response:**
```json
{
  "code": "missing_title",
  "message": "Post title is required",
  "data": {
    "status": 400
  }
}
```

---

## API Endpoints Summary

| Endpoint | Method | Purpose | Returns |
|----------|--------|---------|---------|
| `/sitemap` | GET | Full site data for LLM | Posts, pages, categories, tags, authors |
| `/categories` | GET | Category list | All categories (for dropdowns) |
| `/authors` | GET | Author list | All authors (for dropdowns) |
| `/posts` | POST | Create new post | Created post data |

**Base URL:** `{site_url}/wp-json/vernal-contentum/v1/`  
**Authentication:** All endpoints require `X-API-Key` header

---

## Connection Data Format

When user pastes connection data from WordPress plugin, they get:

```json
{
  "site_url": "https://vernalcontentum.com",
  "username": "admin",
  "api_key": "vc_bab6f67653b7eb32114952ad9fa76e4350bdfbae108e169e76533c72f45fef58",
  "app_password": "vc_bab6f67653b7eb32114952ad9fa76e4350bdfbae108e169e76533c72f45fef58",
  "api_endpoint": "https://vernalcontentum.com/wp-json/vernal-contentum/v1/"
}
```

**Field Mapping:**
- `site_url` → WordPress Site URL field
- `username` → Username field
- `api_key` or `app_password` → App Password field (same value)
- `api_endpoint` → Store internally for API calls

---

## Ready for Web App Integration

✅ **Confirmed:** Plugin can provide sitemap, posts data, authors data  
✅ **Confirmed:** Plugin can accept post creation with title, body, featured image, author, category  
✅ **Ready:** All endpoints are functional and tested

**Next Step:** Provide this specification to web app LLM with your specific formatting/requirements.

