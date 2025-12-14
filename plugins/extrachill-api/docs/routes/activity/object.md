# Object Resolver

Resolve and retrieve data for different object types (posts, comments, artists) with context-aware permission checks.

## Endpoint

### Get Object

**Endpoint**: `GET /wp-json/extrachill/v1/object`

**Purpose**: Resolve object data by type with automatic blog switching and permission enforcement.

**Permission**: Requires logged-in user

**Parameters**:
- `object_type` (string, required) - Object type: `post`, `comment`, or `artist`
- `blog_id` (integer, required) - Blog ID containing the object
- `id` (string, required) - Object identifier (post ID, comment ID, or artist ID)

**Response - Post** (HTTP 200):
```json
{
  "object_type": "post",
  "blog_id": 1,
  "id": 789,
  "post_type": "post",
  "status": "publish",
  "title": "Post Title",
  "excerpt": "Post excerpt text",
  "content": "<p>Full HTML content</p>",
  "author_id": 456,
  "permalink": "https://example.com/post-title/",
  "date_gmt": "2025-01-15T10:30:00+00:00"
}
```

**Response - Comment** (HTTP 200):
```json
{
  "object_type": "comment",
  "blog_id": 1,
  "id": 456,
  "post_id": 789,
  "author_id": 123,
  "content": "Comment text",
  "date_gmt": "2025-01-15T10:30:00+00:00"
}
```

**Response - Artist** (HTTP 200):
```json
{
  "object_type": "artist",
  "blog_id": 1,
  "id": 101,
  "artist": {
    "id": 101,
    "name": "Artist Name",
    "slug": "artist-slug",
    "bio": "Artist biography",
    "profile_image_url": "https://..."
  }
}
```

**File**: `inc/routes/activity/object.php`

## Permission Model

| Object Type | Permission Rules |
|-------------|-----------------|
| Post | Requires `edit_post` capability OR post must be published |
| Comment | Requires `edit_comment` capability OR comment must be approved OR user must be comment author |
| Artist | Requires `ec_can_manage_artist()` - user must manage the artist profile |

## Implementation Details

- Automatically switches to the specified blog context before resolving objects
- Restores original blog context after resolution, even if errors occur
- Post access checks verify both capability and publication status
- Comment access checks verify approval status or ownership before allowing unapproved comments
- Artist resolution delegates to `extrachill_api_build_artist_response()` if available

## Error Responses

**Not Logged In** (HTTP 401):
```json
{
  "code": "rest_forbidden",
  "message": "Must be logged in.",
  "data": { "status": 401 }
}
```

**Missing Parameters** (HTTP 400):
```json
{
  "code": "invalid_params",
  "message": "blog_id and id are required.",
  "data": { "status": 400 }
}
```

**Invalid Blog ID** (HTTP 400):
```json
{
  "code": "invalid_blog_id",
  "message": "Invalid blog_id.",
  "data": { "status": 400 }
}
```

**Object Not Found** (HTTP 404):
```json
{
  "code": "not_found",
  "message": "Post not found.",
  "data": { "status": 404 }
}
```

**Permission Denied** (HTTP 403):
```json
{
  "code": "rest_forbidden",
  "message": "Post not accessible.",
  "data": { "status": 403 }
}
```

**Unsupported Type** (HTTP 400):
```json
{
  "code": "unsupported_object_type",
  "message": "Unsupported object_type.",
  "data": { "status": 400 }
}
```

**Missing Dependencies** (HTTP 500):
```json
{
  "code": "dependency_missing",
  "message": "Artist platform not active.",
  "data": { "status": 500 }
}
```

## Blog Switching

- Uses `switch_to_blog()` to change context before accessing objects
- Guaranteed restoration via try/finally block
- Critical for multisite environments where objects exist on different network sites

## Dependencies

- **extrachill-api**: Core resolver functions
- **extrachill-artist-platform**: Required for artist object type resolution
- **WordPress Multisite**: For blog switching functionality

## Integration

Used by activity streams and object references where context-aware access control is required:
- Activity item detail resolution
- Comment thread reconstruction
- Artist relationship verification
- Multisite navigation and linking
