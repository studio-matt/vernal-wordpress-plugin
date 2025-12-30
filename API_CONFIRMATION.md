# WordPress Plugin API - Confirmed Capabilities

## ✅ CONFIRMED: What the Plugin PROVIDES

### 1. Sitemap Data
**Endpoint:** `GET /wp-json/vernal-contentum/v1/sitemap`  
**Authentication:** `X-API-Key` header

**Returns:**
```json
{
  "site_url": "https://vernalcontentum.com",
  "home_url": "https://vernalcontentum.com",
  "site_name": "Site Name",
  "last_updated": "2024-12-30 16:00:00",
  "posts": [...],      // All published posts with metadata
  "pages": [...],      // All published pages
  "categories": [...], // All categories
  "tags": [...],       // All tags
  "authors": [...],   // All authors
  "post_types": [...]  // All public post types
}
```

**Posts Array Structure:**
```json
{
  "id": 123,
  "title": "Post Title",
  "url": "https://site.com/post-slug/",
  "slug": "post-slug",
  "date": "2024-12-30 12:00:00",
  "modified": "2024-12-30 14:00:00",
  "excerpt": "Post excerpt...",
  "author": "Author Name",
  "author_id": 1,
  "categories": ["Category 1", "Category 2"],
  "tags": ["tag1", "tag2"],
  "word_count": 500,
  "featured_image": "https://site.com/image.jpg"
}
```

### 2. Posts-Only Sitemap
**Note:** The current `/sitemap` endpoint returns ALL data (posts, pages, categories, tags, authors).

**To get only posts:** Filter the response - use `response.posts` array from the sitemap endpoint.

**OR** we can add a dedicated endpoint: `GET /wp-json/vernal-contentum/v1/sitemap/posts`

### 3. Authors-Only Sitemap
**Endpoint:** `GET /wp-json/vernal-contentum/v1/authors`  
**Authentication:** `X-API-Key` header

**Returns:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "username": "admin",
      "display_name": "Admin User",
      "email": "admin@example.com",
      "first_name": "Admin",
      "last_name": "User"
    }
  ],
  "count": 1
}
```

**OR** filter from main sitemap: `response.authors` array

### 4. Categories List
**Endpoint:** `GET /wp-json/vernal-contentum/v1/categories`  
**Authentication:** `X-API-Key` header

**Returns:**
```json
{
  "success": true,
  "data": [
    {
      "id": 5,
      "name": "Category Name",
      "slug": "category-slug",
      "description": "Category description",
      "count": 10,
      "parent": 0
    }
  ],
  "count": 1
}
```

---

## ✅ CONFIRMED: What the Plugin ACCEPTS

### Create Post
**Endpoint:** `POST /wp-json/vernal-contentum/v1/posts`  
**Authentication:** `X-API-Key` header  
**Content-Type:** `application/json`

**Required Fields:**
- `title` (string) - Post title
- `content` (string) - Post body/content (HTML allowed)

**Optional Fields:**
- `status` (string) - Post status: `draft`, `publish`, `pending` (default: `draft`)
- `post_type` (string) - Post type (default: `post`)
- `author_id` (integer) - WordPress user ID for author
- `category_id` (integer) - Single category ID
- `category_ids` (array) - Multiple category IDs: `[5, 10, 15]`
- `tags` (array) - Array of tag names: `["tag1", "tag2"]`
- `featured_image_url` (string) - URL to featured image (will be downloaded and set)
- `excerpt` (string) - Post excerpt
- `post_date` (string) - Publication date: `"2024-12-30 12:00:00"`
- `meta` (object) - Custom meta fields: `{"custom_field": "value"}`

**Request Example:**
```json
{
  "title": "My New Post",
  "content": "<p>Post content here...</p>",
  "status": "draft",
  "author_id": 1,
  "category_id": 5,
  "featured_image_url": "https://example.com/image.jpg",
  "excerpt": "Post excerpt",
  "tags": ["tag1", "tag2"]
}
```

**Response Example:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "title": "My New Post",
    "status": "draft",
    "url": "https://site.com/my-new-post/",
    "edit_url": "https://site.com/wp-admin/post.php?action=edit&post=123"
  }
}
```

---

## Summary

### ✅ PROVIDES:
1. **Full Sitemap** - `/sitemap` (includes posts, pages, categories, tags, authors)
2. **Posts Data** - Available in sitemap response (`posts` array)
3. **Authors Data** - Available in sitemap response (`authors` array) OR `/authors` endpoint
4. **Categories** - `/categories` endpoint

### ✅ ACCEPTS:
1. **Post Creation** - `/posts` (POST)
   - Title ✅
   - Body/Content ✅
   - Featured Image ✅ (via URL)
   - Author ✅ (via `author_id`)
   - Category ✅ (via `category_id` or `category_ids`)
   - Plus: status, tags, excerpt, date, custom meta

---

## Questions to Clarify for Web App Integration

1. **Sitemap Structure:**
   - Do you want separate endpoints for posts-only and authors-only?
   - Or is filtering the main sitemap response sufficient?

2. **Post Creation:**
   - Are all the optional fields (tags, excerpt, date, meta) needed?
   - Any additional fields required?

3. **Featured Image:**
   - Currently accepts URL - is that sufficient?
   - Or do you need to upload image files directly?

4. **Error Handling:**
   - What error responses are needed?
   - Any specific error codes or messages?

