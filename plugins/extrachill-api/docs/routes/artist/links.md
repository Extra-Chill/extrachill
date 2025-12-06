# Artist Link Page Data Endpoint

## Route
`GET/PUT /wp-json/extrachill/v1/artists/{id}/links`

## Purpose
Retrieve and update link page presentation data for an artist. This includes button links organized in sections, CSS variables for styling, settings for behavior, and background images. This is the canonical endpoint for link page data management.

## Permission
- **GET**: Readable by any user who can manage the artist
- **PUT**: Writable only by artist owner or admin using `ec_can_manage_artist()` permission check

## GET Request
No request body needed. Artist ID is extracted from the URL path.

### GET Response
```json
{
  "id": 101,
  "links": [
    {
      "section_title": "Music",
      "links": [
        {
          "id": "link_123",
          "link_text": "Listen on Spotify",
          "link_url": "https://open.spotify.com/artist/..."
        },
        {
          "id": "link_124",
          "link_text": "Apple Music",
          "link_url": "https://music.apple.com/..."
        }
      ]
    },
    {
      "section_title": "Social",
      "links": [
        {
          "id": "link_125",
          "link_text": "Instagram",
          "link_url": "https://instagram.com/..."
        }
      ]
    }
  ],
  "css_vars": {
    "--link-page-button-bg-color": "#ffffff",
    "--link-page-text-color": "#000000",
    "--link-page-button-text-color": "#333333"
  },
  "settings": {
    "link_expiration_enabled": false,
    "redirect_enabled": false,
    "redirect_target_url": "",
    "youtube_embed_enabled": true,
    "meta_pixel_id": "",
    "google_tag_id": "",
    "subscribe_display_mode": "icon_modal",
    "subscribe_description": "Get updates from this artist",
    "social_icons_position": "above"
  },
  "background_image_id": 202,
  "background_image_url": "https://example.com/wp-content/uploads/background.jpg"
}
```

## PUT Request
Supports partial updates. Only include fields you want to change.

```json
{
  "links": [
    {
      "section_title": "Music",
      "links": [
        {
          "id": "link_123",
          "link_text": "Spotify",
          "link_url": "https://open.spotify.com/artist/..."
        }
      ]
    }
  ],
  "css_vars": {
    "--link-page-button-bg-color": "#ff0000"
  },
  "settings": {
    "youtube_embed_enabled": false,
    "subscribe_display_mode": "banner"
  }
}
```

### PUT Response
Returns the full updated link page object with all fields (same structure as GET).

## Update Behavior

| Field | Update Strategy | Notes |
| --- | --- | --- |
| `links` | Full replacement | Sending `[]` clears all sections. Include all desired sections in each update. |
| `css_vars` | Merged | Only provided variables are updated; existing variables not mentioned are preserved. |
| `settings` | Merged | Only provided settings are updated; existing settings not mentioned are preserved. |
| `background_image_id` | Replace | Set via the `/media` endpoint instead. |

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `missing_permission` | 403 | Current user cannot manage this artist |
| `invalid_artist_id` | 404 | Artist not found |
| `no_link_page` | 404 | Artist has not created a link page |
| `database_error` | 500 | Failed to save link page data |

## Implementation Notes
- Link page data is stored on the link page post (WordPress post of type `link_page`)
- The `id` field represents the link page post ID and is read-only
- Links are organized into sections for better presentation
- CSS variables use standard CSS custom property syntax and are validated
- Settings control optional features like redirects, YouTube embeds, and newsletter subscription display
- Background images should be uploaded via the `/media` endpoint with `link_page_background` context
- The endpoint uses `ec_get_link_page_data()` and `ec_handle_link_page_save()` for data access and persistence

## Available Settings
- `link_expiration_enabled` (boolean) - Enable link expiration dates
- `redirect_enabled` (boolean) - Enable redirect functionality
- `redirect_target_url` (string) - Target URL for redirect
- `youtube_embed_enabled` (boolean) - Allow YouTube video embeds
- `meta_pixel_id` (string) - Facebook Pixel ID for tracking
- `google_tag_id` (string) - Google Analytics or Google Tag Manager ID
- `subscribe_display_mode` (string) - How subscription form appears ('icon_modal', 'banner', etc.)
- `subscribe_description` (string) - Text shown above subscription form
- `social_icons_position` (string) - Position of social icons ('above', 'below', 'hidden')

## Related Endpoints
- `GET/PUT /artists/{id}` - Manage core artist data
- `GET/PUT /artists/{id}/socials` - Manage social media links
- `POST /media` - Upload background images
- `GET /artists/{id}/analytics` - View link page analytics

## Usage Examples

### Get Link Page Data
```bash
curl -X GET "http://site.local/wp-json/extrachill/v1/artists/123/links"
```

### Update Link Page Links and Settings
```bash
curl -X PUT "http://site.local/wp-json/extrachill/v1/artists/123/links" \
  -H "Content-Type: application/json" \
  -d '{
    "links": [
      {
        "section_title": "Music",
        "links": [
          {
            "id": "link_1",
            "link_text": "Listen on Spotify",
            "link_url": "https://open.spotify.com/artist/123"
          }
        ]
      }
    ],
    "css_vars": {
      "--link-page-button-bg-color": "#ff6600"
    },
    "settings": {
      "youtube_embed_enabled": true,
      "subscribe_description": "Subscribe for updates!"
    }
  }'
```

### Update Only Settings
```bash
curl -X PUT "http://site.local/wp-json/extrachill/v1/artists/123/links" \
  -H "Content-Type: application/json" \
  -d '{
    "settings": {
      "meta_pixel_id": "123456789",
      "google_tag_id": "G-XXXXXXXXXX"
    }
  }'
```
