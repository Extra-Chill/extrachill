# Artist Core Data Endpoint

## Route
`GET/PUT /wp-json/extrachill/v1/artists/{id}`

## Purpose
Retrieve and update core artist profile data including name, bio, profile images, and link page reference. This is the canonical endpoint for artist profile management.

## Permission
- **GET**: Readable by any user who can manage the artist (owner or admin)
- **PUT**: Writable only by artist owner or admin using `ec_can_manage_artist()` permission check

## GET Request
No request body needed. Artist ID is extracted from the URL path.

### GET Response
```json
{
  "id": 123,
  "name": "Artist Name",
  "slug": "artist-slug",
  "bio": "Artist bio text",
  "profile_image_id": 456,
  "profile_image_url": "https://example.com/wp-content/uploads/image.jpg",
  "header_image_id": 789,
  "header_image_url": "https://example.com/wp-content/uploads/header.jpg",
  "link_page_id": 101
}
```

## PUT Request
Supports partial updates. Only include fields you want to change.

```json
{
  "name": "New Artist Name",
  "bio": "Updated bio text"
}
```

### PUT Response
Returns the full updated artist object with all fields (same structure as GET).

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_permission` | 403 | Current user cannot manage this artist |
| `invalid_artist_id` | 404 | Artist post not found |
| `database_error` | 500 | Failed to save artist data |

## Implementation Notes
- Artist profiles are stored as `artist_profile` post type
- Images are managed separately via the `/media` endpoint - do not send image IDs in PUT requests
- The `link_page_id` is read-only and automatically maintained by the system
- All text fields are automatically sanitized on input

## Related Endpoints
- `GET/PUT /artists/{id}/socials` - Manage social media links
- `GET/PUT /artists/{id}/links` - Manage link page presentation data
- `GET /artists/{id}/analytics` - View link page analytics
- `POST /media` - Upload profile images

## Usage Examples

### Get Artist Profile
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/artists/123"
```

### Update Artist Name and Bio
```bash
curl -X PUT "http://site.local/wp-json/extrachill/v1/artists/123" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Updated Artist",
    "bio": "New bio text"
  }'
```
