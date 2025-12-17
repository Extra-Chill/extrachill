# Artist Social Links Endpoint

## Route
`GET/PUT /wp-json/extrachill/v1/artists/{id}/socials`

## Purpose
Retrieve and update social media links for an artist profile. Social links are displayed on the artist's link page and include platforms like Instagram, Spotify, TikTok, YouTube, and more.

## Permission
- **GET**: Readable by any user who can manage the artist
- **PUT**: Writable only by artist owner or admin using `ec_can_manage_artist()` permission check

## GET Request
No request body needed. Artist ID is extracted from the URL path.

### GET Response
```json
{
  "social_links": [
    {
      "type": "instagram",
      "url": "https://instagram.com/artist"
    },
    {
      "type": "spotify",
      "url": "https://open.spotify.com/artist/abc123"
    },
    {
      "type": "tiktok",
      "url": "https://tiktok.com/@artist"
    }
  ]
}
```

## PUT Request
Full replacement of social links. Send an array with all desired social links. Omitting a previously set platform removes it.

```json
{
  "social_links": [
    {
      "type": "instagram",
      "url": "https://instagram.com/new_artist"
    },
    {
      "type": "youtube",
      "url": "https://youtube.com/@new_artist"
    }
  ]
}
```

To clear all social links, send an empty array:
```json
{
  "social_links": []
}
```

### PUT Response
Returns the updated social links object (same structure as GET).

## Supported Social Platforms
Common platform types include:
- `instagram` - Instagram profile
- `spotify` - Spotify artist
- `tiktok` - TikTok profile
- `youtube` - YouTube channel
- `twitter` - Twitter/X profile
- `soundcloud` - SoundCloud profile
- `bandcamp` - Bandcamp profile
- `facebook` - Facebook page
- Custom types as defined by the system

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_permission` | 403 | Current user cannot manage this artist |
| `invalid_artist_id` | 404 | Artist post not found |
| `invalid_social_links` | 400 | Social links array is malformed |
| `database_error` | 500 | Failed to save social links |

## Implementation Notes
- Social links are stored on the artist profile post using the `extrachill_artist_platform_social_links()` manager
- PUT is a full replacement operation - any existing social links not included in the request will be removed
- URLs are validated and sanitized before storage
- Social links are displayed prominently on the link page (see link page settings for position control)

## Related Endpoints
- `GET/PUT /artists/{id}` - Manage core artist data
- `GET/PUT /artists/{id}/links` - Control link page presentation and settings

## Usage Examples

### Get Artist Social Links
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/artists/123/socials"
```

### Update Social Links
```bash
curl -X PUT "http://site.local/wp-json/extrachill/v1/artists/123/socials" \
  -H "Content-Type: application/json" \
  -d '{
    "social_links": [
      {"type": "instagram", "url": "https://instagram.com/myband"},
      {"type": "spotify", "url": "https://open.spotify.com/artist/xyz"},
      {"type": "tiktok", "url": "https://tiktok.com/@myband"}
    ]
  }'
```

### Clear All Social Links
```bash
curl -X PUT "http://site.local/wp-json/extrachill/v1/artists/123/socials" \
  -H "Content-Type: application/json" \
  -d '{
    "social_links": []
  }'
```
