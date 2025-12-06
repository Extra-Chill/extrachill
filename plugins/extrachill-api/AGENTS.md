# ExtraChill API Plugin

Network-activated REST API infrastructure for the Extra Chill Platform multisite network. Provides centralized endpoint management under the `extrachill/v1` namespace for web, mobile, and AI clients.

## Current Status

**Network Activation**: Required - All endpoints available on every site in the multisite network.

**Production Status**: Active and serving endpoints for extrachill-blocks plugin.

## Architecture

### Singleton Pattern with Automatic Route Discovery

The plugin uses a singleton class that automatically discovers and loads route files from `inc/routes/` directory using PHP's RecursiveIteratorIterator:

```php
ExtraChill_API_Plugin::get_instance();
```

**Route Discovery**:
- Recursively scans `inc/routes/` directory for PHP files
- Loads each route file via `require_once`
- Route files self-register endpoints using WordPress REST API
- Supports nested directory organization (`inc/routes/blocks/`, `inc/routes/community/`)

**Action Hooks**:
- `extrachill_api_bootstrap` - Fires during `plugins_loaded` for initialization
- `extrachill_api_register_routes` - Fires during `rest_api_init` for route registration

### Directory Structure

```
extrachill-api/
├── extrachill-api.php (Main plugin file with singleton class)
└── inc/
    └── routes/
        ├── artist/
        │   ├── artist.php (Core artist data endpoint)
        │   ├── links.php (Link page data endpoint)
        │   ├── socials.php (Social links endpoint)
        │   ├── permissions.php (Permission check endpoint)
        │   ├── roster.php (Artist roster endpoint)
        │   ├── subscribe.php (Artist subscription endpoint)
        │   └── subscribers.php (Subscriber management endpoint)
        ├── blocks/
        │   ├── ai-adventure.php (AI Adventure block endpoint)
        │   ├── image-voting.php (Image voting block endpoint)
        │   └── trivia.php (Trivia block endpoint)
        ├── community/
        │   └── user-mentions.php (User search for mentions)
        ├── events/
        │   └── event-submissions.php (Event submission proxy)
        ├── media/
        │   └── upload.php (Unified media upload endpoint)
        └── tools/
            └── qr-code.php (QR code generator)
```

## Current Endpoints

All endpoints are under the `extrachill/v1` namespace.

### 1. User Search (Community Mentions)

**Endpoint**: `GET /wp-json/extrachill/v1/users/search`

**Purpose**: Search users for @mentions in community posts and comments.

**Parameters**:
- `search` (string, required) - Search term for username/display name
- Implements WordPress nonce verification
- Returns user ID, display name, and avatar URL

**File**: `inc/routes/community/user-mentions.php`

**Used By**: extrachill-community plugin for @mention functionality

### 2. Image Voting Vote Count

**Endpoint**: `GET /wp-json/extrachill/v1/image-voting/vote-count/{post_id}/{instance_id}`

**Purpose**: Retrieve vote counts for image voting block instances.

**Parameters**:
- `post_id` (int, required) - WordPress post ID containing the block
- `instance_id` (string, required) - Unique block instance identifier
- Returns vote counts per image option

**File**: `inc/routes/blocks/image-voting.php`

**Used By**: extrachill-blocks plugin (Image Voting block)

### 3. AI Adventure Story Generation

**Endpoint**: `POST /wp-json/extrachill/v1/ai-adventure`

**Purpose**: Generate AI-powered adventure story segments.

**Parameters**:
- `action` (string, required) - User action/choice in the story
- `history` (array, optional) - Previous story segments for context
- Requires extrachill-ai-client plugin for AI provider access
- Returns generated story segment and options for next action

**File**: `inc/routes/blocks/ai-adventure.php`

**Used By**: extrachill-blocks plugin (AI Adventure block)

**Dependencies**: extrachill-ai-client (network-activated)

### 4. Event Submission Flow Proxy

**Endpoint**: `POST /wp-json/extrachill/v1/event-submissions`

**Purpose**: Accept public event submissions, validate Cloudflare Turnstile tokens, store flyers via Data Machine file storage, create a Data Machine job, and queue the configured flow.

**Parameters**:
- `flow_id` (int, required) – Target Data Machine flow ID (defined in block attributes)
- `contact_name`, `contact_email`, `event_title`, `event_date` (required strings)
- Optional context fields (`event_time`, `venue_name`, `event_city`, `event_lineup`, `event_link`, `notes`)
- `flyer` (file, optional) – Uploaded flyer image stored via Data Machine repository helpers
- `turnstile_response` (string, required) – Cloudflare Turnstile token validated through extrachill-multisite helpers

**Security & Flow**:
1. Validates Turnstile token using `ec_verify_turnstile_response()` from extrachill-multisite
2. Sanitizes submission payload and (optionally) stores the flyer in Data Machine’s `FileStorage`
3. Creates a Data Machine job + merges submission metadata via `datamachine_merge_engine_data()`
4. Queues `datamachine_run_flow_now` through Action Scheduler
5. Fires `extrachill_event_submission` action with submission + job context for downstream automation

**File**: `inc/routes/events/event-submissions.php`

**Used By**: `extrachill-events` plugin's Event Submission block + front-end form handlers

### 5. QR Code Generator

**Endpoint**: `POST /wp-json/extrachill/v1/tools/qr-code`

**Purpose**: Generate high-resolution print-ready QR codes for any URL.

**Parameters**:
- `url` (string, required) - The URL to encode in the QR code
- `size` (int, optional) - QR code size in pixels (default: 1000, max: 2000)

**Response**:
```json
{
    "image_url": "data:image/png;base64,...",
    "url": "https://example.com",
    "size": 1000
}
```

**Permission**: Public (no authentication required)

**File**: `inc/routes/tools/qr-code.php`

**Used By**: extrachill-admin-tools plugin (QR Code Generator tool)

**Dependencies**: Endroid QR Code library (Composer dependency)

### 6. Unified Media Upload

**Endpoint**: `POST/DELETE /wp-json/extrachill/v1/media`

**Purpose**: Centralized image upload and management for all platform contexts. Handles upload, assignment, old image cleanup, and deletion.

**Methods**:
- `POST` - Upload and assign image (automatically deletes old image when replaced)
- `DELETE` - Remove assignment AND delete attachment from media library

**Parameters**:
- `context` (string, required) - Upload context determining storage location
- `target_id` (int, required for most contexts) - Target entity ID
- `file` (file, POST only) - Image file (JPG, PNG, GIF, WebP; max 5MB)

**Supported Contexts**:

| Context | target_id | Storage Location |
|---------|-----------|------------------|
| `user_avatar` | user_id | `custom_avatar_id` user meta |
| `artist_profile` | artist_id | Artist post thumbnail |
| `artist_header` | artist_id | `_artist_profile_header_image_id` meta |
| `link_page_profile` | artist_id | Artist thumbnail + `_link_page_profile_image_id` on link page |
| `link_page_background` | artist_id | `_link_page_background_image_id` on link page |
| `content_embed` | optional post_id | Attachment only (no meta assignment) |

**Permission Logic**:
- `user_avatar`: Current user must match `target_id`
- Artist contexts: Uses `ec_can_manage_artist()` from extrachill-artist-platform
- `content_embed`: Any logged-in user

**POST Response**:
```json
{
    "attachment_id": 123,
    "url": "https://example.com/wp-content/uploads/image.jpg",
    "context": "user_avatar",
    "target_id": 1
}
```

**DELETE Response**:
```json
{
    "deleted": true,
    "context": "user_avatar",
    "target_id": 1
}
```

**File**: `inc/routes/media/upload.php`

**Used By**:
- extrachill-users (avatar upload on bbPress profile edit)
- extrachill-community (TinyMCE image embed)
- Future: React-based Link Page Editor

**Dependencies**: extrachill-artist-platform (for artist context permission checks)

## Artist API

Foundational REST API for artist data management. Provides three endpoints that serve as the canonical access layer for all artist-related operations.

### 7. Artist Core Data

**Endpoint**: `GET/PUT /wp-json/extrachill/v1/artists/{id}`

**Purpose**: Retrieve and update core artist profile data.

**GET Response**:
```json
{
  "id": 123,
  "name": "Artist Name",
  "slug": "artist-slug",
  "bio": "Artist bio text",
  "profile_image_id": 456,
  "profile_image_url": "https://...",
  "header_image_id": 789,
  "header_image_url": "https://...",
  "link_page_id": 101
}
```

**PUT Request** (partial updates supported):
```json
{
  "name": "New Artist Name",
  "bio": "Updated bio"
}
```

**PUT Response**: Returns updated artist data (same structure as GET)

**Permission**: `ec_can_manage_artist()` - user must be artist owner or admin

**File**: `inc/routes/artist/artist.php`

**Notes**:
- Images managed via `/media` endpoint with `artist_profile` or `artist_header` context
- `link_page_id` is read-only

---

### 8. Artist Social Links

**Endpoint**: `GET/PUT /wp-json/extrachill/v1/artists/{id}/socials`

**Purpose**: Retrieve and update social icon links (Instagram, Spotify, etc.).

**GET Response**:
```json
{
  "social_links": [
    {"type": "instagram", "url": "https://instagram.com/artist"},
    {"type": "spotify", "url": "https://open.spotify.com/artist/..."}
  ]
}
```

**PUT Request** (full replacement):
```json
{
  "social_links": [
    {"type": "instagram", "url": "https://instagram.com/artist"},
    {"type": "tiktok", "url": "https://tiktok.com/@artist"}
  ]
}
```

**PUT Response**: Returns updated social links (same structure as GET)

**Permission**: `ec_can_manage_artist()`

**File**: `inc/routes/artist/socials.php`

**Notes**:
- Uses `extrachill_artist_platform_social_links()` manager
- PUT is full replacement (sending `[]` clears all socials)
- Social links stored on artist profile, displayed on link page

---

### 9. Artist Link Page Data

**Endpoint**: `GET/PUT /wp-json/extrachill/v1/artists/{id}/links`

**Purpose**: Retrieve and update link page presentation data (button links, styling, settings).

**GET Response**:
```json
{
  "id": 101,
  "links": [
    {
      "section_title": "Music",
      "links": [
        {"id": "link_123", "link_text": "Spotify", "link_url": "https://..."}
      ]
    }
  ],
  "css_vars": {
    "--link-page-button-bg-color": "#ffffff",
    "--link-page-text-color": "#000000"
  },
  "settings": {
    "link_expiration_enabled": false,
    "weekly_notifications_enabled": false,
    "redirect_enabled": false,
    "redirect_target_url": "",
    "youtube_embed_enabled": true,
    "meta_pixel_id": "",
    "google_tag_id": "",
    "subscribe_display_mode": "icon_modal",
    "subscribe_description": "",
    "social_icons_position": "above"
  },
  "background_image_id": 202,
  "background_image_url": "https://..."
}
```

**PUT Request** (partial updates supported):
```json
{
  "links": [...],
  "css_vars": {"--link-page-button-bg-color": "#ff0000"},
  "settings": {"youtube_embed_enabled": false}
}
```

**PUT Response**: Returns updated link page data (same structure as GET)

**Permission**: `ec_can_manage_artist()`

**File**: `inc/routes/artist/links.php`

**Update Behavior**:
- `links`: Full replacement (sending `[]` clears all sections)
- `css_vars`: Merged with existing values
- `settings`: Merged with existing values (only provided fields updated)

**Notes**:
- Returns 404 if artist has no link page
- Background image managed via `/media` endpoint with `link_page_background` context
- Uses `ec_handle_link_page_save()` for write operations

## Response Contract

All endpoints follow a standardized response format:

### Success Responses (HTTP 200)

Return data directly without wrappers:

```php
return rest_ensure_response( array(
    'message' => 'Operation completed',
    'url'     => $url,
    // ... other data properties
) );
```

**Fire-and-forget endpoints** use semantic keys:
- `analytics/link-click`: `{ "tracked": true }`
- `analytics/view`: `{ "recorded": true }`

### Error Responses (HTTP 4xx/5xx)

Use `WP_Error` with appropriate status codes:

```php
return new WP_Error(
    'error_code',
    'Human-readable error message',
    array( 'status' => 400 )
);
```

**Standard status codes**:
- `400` - Bad Request (validation errors, invalid input)
- `403` - Forbidden (permission denied, security check failed)
- `404` - Not Found (resource doesn't exist)
- `500` - Server Error (missing dependencies, failed operations)

### JavaScript Consumption Pattern

```javascript
fetch(endpoint, options)
    .then(response => {
        if (!response.ok) {
            return response.json().then(err => Promise.reject(err));
        }
        return response.json();
    })
    .then(data => {
        // Access data directly: data.url, data.message, etc.
    })
    .catch(error => {
        // error.message contains error text
    });
```

**Key principle**: No `success` key in responses. HTTP status codes determine success/failure. Data properties are accessed directly.

## Integration Patterns

### For Plugin Developers
**Event Submission Hook**:

```php
add_action( 'extrachill_event_submission', function( array $submission, array $context ) {
    // $submission contains sanitized fields + optional flyer metadata
    // $context includes flow_id, job_id, action_id, flow_name
} );
```

Triggered after a submission is queued so platform plugins (notifications, analytics, moderation) can react without duplicating REST logic.

**Adding New Endpoints**:

1. Create route file in `inc/routes/` (or subdirectory)
2. Register endpoint using WordPress REST API conventions
3. Plugin automatically discovers and loads on activation

Example route file structure:

```php
<?php
/**
 * Example endpoint registration
 */

add_action('extrachill_api_register_routes', function() {
    register_rest_route('extrachill/v1', '/my-endpoint', [
        'methods' => 'GET',
        'callback' => 'my_endpoint_callback',
        'permission_callback' => 'my_permission_check',
        'args' => [
            'param' => [
                'required' => true,
                'validate_callback' => 'my_validation',
            ],
        ],
    ]);
});

function my_endpoint_callback($request) {
    // Implementation
    return rest_ensure_response(['data' => 'value']);
}

function my_permission_check($request) {
    // Permission logic
    return current_user_can('read');
}
```

### For Client Developers

**Accessing Endpoints**:

JavaScript example:
```javascript
fetch('/wp-json/extrachill/v1/users/search?search=username', {
    method: 'GET',
    headers: {
        'X-WP-Nonce': wpApiSettings.nonce
    }
})
.then(response => response.json())
.then(data => console.log(data));
```

PHP example:
```php
$response = wp_remote_get(
    rest_url('extrachill/v1/users/search'),
    [
        'body' => ['search' => 'username'],
        'headers' => [
            'X-WP-Nonce' => wp_create_nonce('wp_rest')
        ]
    ]
);
```

## Migration Strategy

**Goal**: Replace legacy admin-ajax.php handlers across the platform with versioned REST endpoints.

**Priority Targets**:
1. Community plugin AJAX handlers (voting, notifications, mentions)
2. Newsletter plugin subscription handlers
3. Artist platform analytics tracking
4. Admin tools background operations

**Benefits of Migration**:
- **Versioning**: `extrachill/v1` namespace allows API evolution
- **Standards Compliance**: WordPress REST API conventions
- **Authentication**: Built-in authentication and permission callbacks
- **Documentation**: Self-documenting via REST API schema
- **Testing**: Easier endpoint testing and validation
- **Mobile Support**: Native REST API compatibility for future mobile app

## Security Implementation

**All endpoints implement**:
1. **Nonce Verification**: WordPress REST API nonce system
2. **Permission Callbacks**: User capability checks
3. **Input Validation**: Parameter validation callbacks
4. **Sanitization**: `sanitize_text_field()`, `absint()`, etc.
5. **Output Escaping**: `rest_ensure_response()` with sanitized data

**Security Best Practices**:
- Never expose sensitive data without authentication
- Always validate and sanitize user input
- Use WordPress core functions for security checks
- Implement rate limiting for resource-intensive endpoints
- Log security-relevant events for auditing

## Performance Considerations

**Caching Strategy**:
- Endpoints return fresh data by default
- Consider implementing transient caching for expensive queries
- Use WordPress object cache for frequently accessed data
- Leverage browser caching via HTTP headers

**Query Optimization**:
- Use prepared statements for database queries
- Limit query results with pagination
- Avoid N+1 query patterns
- Index database tables for search performance

## Development Workflow

**Local Development**:
```bash
cd extrachill-plugins/extrachill-api

# Install dependencies
composer install

# Run tests
composer test

# PHP linting
composer run lint:php

# Create production build
./build.sh
```

**Adding New Route**:
1. Create PHP file in `inc/routes/` (or appropriate subdirectory)
2. Use `extrachill_api_register_routes` action hook
3. Follow WordPress REST API conventions
4. Add tests for new endpoint
5. Update this documentation

**Testing Endpoints**:
```bash
# Using WP-CLI
wp rest list

# Using curl
curl -X GET "http://site.local/wp-json/extrachill/v1/users/search?search=test" \
  -H "X-WP-Nonce: nonce_value"
```

## Dependencies

**Required**:
- WordPress 5.0+ (REST API support)
- PHP 7.4+

**Network Dependencies**:
- **extrachill-multisite**: Network-activated foundation (recommended)
- **extrachill-ai-client**: Required for AI Adventure endpoint only

**Optional Integration**:
- **extrachill-blocks**: Primary consumer of current endpoints
- **extrachill-community**: Uses user search endpoint

## Future Roadmap

**Planned Endpoints**:
1. Artist shop data (`/artists/{id}/shop`) - WooCommerce integration
2. Newsletter subscription management
3. Community voting and reactions
4. Admin tools background operations
5. Mobile app authentication

**Infrastructure Improvements**:
1. Centralized authentication helpers
2. Shared permission callback library
3. Response formatting standards
4. Error handling middleware
5. Rate limiting implementation
6. API documentation generator

## Cross-References

**Platform Documentation**:
- [Root AGENTS.md - Architectural Patterns](../../AGENTS.md#architectural-patterns)
- [extrachill-blocks AGENTS.md](../extrachill-blocks/AGENTS.md) - Endpoint consumers
- [extrachill-community AGENTS.md](../extrachill-community/AGENTS.md) - User search integration
- [extrachill-ai-client AGENTS.md](../extrachill-ai-client/AGENTS.md) - AI provider integration

**Related Files**:
- `/.github/build.sh` - Shared build script (symlinked)
- `composer.json` - Dependencies and scripts
- `.buildignore` - Production build exclusions

## Architectural Notes

**Why Singleton Pattern**: Ensures single instance manages route discovery and prevents duplicate registrations.

**Why Automatic Discovery**: Allows modular endpoint organization without manual registration in main plugin file.

**Why `extrachill/v1` Namespace**: Enables API versioning for future breaking changes while maintaining backwards compatibility.

**Why Network Activation**: Ensures consistent API surface across all sites in multisite network.

**Cross-Plugin Communication**: Follows platform pattern of function existence checks and filter-based integration.
