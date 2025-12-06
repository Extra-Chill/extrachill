# Unified Media Upload Endpoint

## Route
`POST/DELETE /wp-json/extrachill/v1/media`

## Purpose
Centralized image upload and management for all platform contexts. Handles upload, assignment, old image cleanup, and deletion across user avatars, artist profiles, link pages, and content embeds.

## Permission
- **POST**: Varies by context - some require logged-in user, artist contexts require `ec_can_manage_artist()`
- **DELETE**: Same permission requirements as POST for the context

## Supported Contexts

| Context | target_id | Storage Location | Permission |
| --- | --- | --- | --- |
| `user_avatar` | user_id | `custom_avatar_id` user meta | Must be own user |
| `artist_profile` | artist_id | Artist post thumbnail | Must manage artist |
| `artist_header` | artist_id | `_artist_profile_header_image_id` meta | Must manage artist |
| `link_page_background` | artist_id | `_link_page_background_image_id` meta | Must manage artist |
| `content_embed` | post_id (optional) | Attachment only | Any logged-in user |

## POST Request (Upload)

```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/media" \
  -F "file=@/path/to/image.jpg" \
  -F "context=user_avatar" \
  -F "target_id=1"
```

### Request Parameters
| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `context` | string | Yes | Upload context (see supported contexts table) |
| `target_id` | integer | Required for most contexts | Entity ID (user ID, artist ID, or post ID depending on context) |
| `file` | file | Yes | Image file (JPG, PNG, GIF, WebP; max 5MB) |

### POST Response
```json
{
  "attachment_id": 123,
  "url": "https://example.com/wp-content/uploads/2025/01/image.jpg",
  "context": "user_avatar",
  "target_id": 1
}
```

## DELETE Request (Delete)

```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/media" \
  -H "Content-Type: application/json" \
  -d '{
    "context": "user_avatar",
    "target_id": 1
  }'
```

### Request Parameters
| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `context` | string | Yes | Same context used in POST |
| `target_id` | integer | Yes | Same target ID as in POST |

### DELETE Response
```json
{
  "deleted": true,
  "context": "user_avatar",
  "target_id": 1
}
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_context` | 400 | Context parameter is missing |
| `invalid_context` | 400 | Context is not recognized |
| `missing_target_id` | 400 | target_id is required for this context but missing |
| `invalid_file` | 400 | File is missing, too large, or invalid format |
| `missing_permission` | 403 | Current user doesn't have permission for this context |
| `invalid_target` | 404 | Target entity (user, artist, post) not found |
| `database_error` | 500 | Failed to save image metadata |

## Implementation Notes
- Files are validated before upload (JPG, PNG, GIF, WebP only; max 5MB)
- Old images are automatically deleted when replaced (for POST requests)
- Images are stored in WordPress media library
- Metadata assignment varies by context (thumbnails, user meta, post meta, etc.)
- DELETE removes both the meta assignment AND the attachment from media library
- Permission checking uses context-appropriate functions (`ec_can_manage_artist()`, capability checks, etc.)

## File Upload Validation
- **Allowed formats**: JPG, JPEG, PNG, GIF, WebP
- **Maximum size**: 5MB
- **MIME types**: image/jpeg, image/png, image/gif, image/webp

## Related Endpoints
- `GET/PUT /artists/{id}` - Includes image URLs for artist profiles
- `GET/PUT /artists/{id}/links` - Includes background image URL

## Usage Examples

### Upload User Avatar
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/media" \
  -H "X-WP-Nonce: nonce_value" \
  -F "file=@avatar.jpg" \
  -F "context=user_avatar" \
  -F "target_id=1"
```

### Upload Artist Profile Image
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/media" \
  -H "X-WP-Nonce: nonce_value" \
  -F "file=@profile.png" \
  -F "context=artist_profile" \
  -F "target_id=123"
```

### Upload Link Page Background
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/media" \
  -H "X-WP-Nonce: nonce_value" \
  -F "file=@background.jpg" \
  -F "context=link_page_background" \
  -F "target_id=123"
```

### Delete Avatar
```bash
curl -X DELETE "http://site.local/wp-json/extrachill/v1/media" \
  -H "X-WP-Nonce: nonce_value" \
  -H "Content-Type: application/json" \
  -d '{
    "context": "user_avatar",
    "target_id": 1
  }'
```

## Permission Details

### user_avatar
- Current user must match `target_id`
- Cannot upload avatar for other users

### Artist Contexts (artist_profile, artist_header, link_page_background)
- Uses `ec_can_manage_artist()` permission check
- User must be artist owner or admin
- Artist with `target_id` must exist

### content_embed
- Any logged-in user can upload
- Image stored in media library only (no meta assignment)
- Optional `target_id` for context (e.g., post being edited)

## Integration
This endpoint centralizes image management across the platform, eliminating duplicate upload logic in individual plugins. All image uploads should route through this endpoint.
