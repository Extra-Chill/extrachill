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
    ├── auth/
    │   └── extrachill-link-auth.php (Cross-domain authentication)
    └── routes/
        ├── admin/
        │   ├── ad-free-license.php (Ad-free license management)
        │   └── team-members.php (Team member sync and management)
        ├── analytics/
        │   ├── link-click.php (Track link page clicks)
        │   ├── link-page.php (Track link page views)
        │   └── view-count.php (Track content views)
        ├── artist/
        │   ├── analytics.php (Artist link page analytics)
        │   ├── artist.php (Core artist data CRUD)
        │   ├── links.php (Link page data management)
        │   ├── socials.php (Social links management)
        │   ├── permissions.php (Permission check)
        │   ├── roster.php (Roster invite management)
        │   ├── subscribe.php (Subscription signup)
        │   └── subscribers.php (Subscriber management)
        ├── blocks/
        │   ├── ai-adventure.php (AI adventure story generation)
        │   ├── band-name.php (Band name generator)
        │   ├── image-voting.php (Image voting vote counts)
        │   ├── image-voting-vote.php (Vote on images)
        │   └── rapper-name.php (Rapper name generator)
        ├── chat/
        │   ├── history.php (Clear chat history)
        │   └── message.php (Send/receive chat messages)
        ├── community/
        │   ├── upvote.php (Topic/reply upvotes)
        │   └── user-mentions.php (User search for mentions)
        ├── docs/
        │   └── docs-info.php (Documentation endpoint info)
        ├── events/
        │   └── event-submissions.php (Event submission proxy)
        ├── media/
        │   └── upload.php (Unified media upload)
        ├── newsletter/
        │   └── subscription.php (Newsletter subscription)
        ├── shop/
        │   ├── products.php (WooCommerce product CRUD)
        │   └── stripe-connect.php (Stripe Connect management)
        ├── tools/
        │   └── qr-code.php (QR code generator)
        └── users/
            ├── artists.php (User artist relationships)
            ├── search.php (User search endpoint)
            └── users.php (User profile endpoint)
```

## Current Endpoints

All endpoints are under the `extrachill/v1` namespace.

### Analytics Endpoints

#### 1. Link Click Tracking

**Endpoint**: `POST /wp-json/extrachill/v1/analytics/link-click`

**Purpose**: Track clicks on artist link pages with URL normalization and context.

**Parameters**:
- `link_url` (string, required) - The URL that was clicked
- `source_page` (string, optional) - The link page slug that hosted the click

**Response**: `{ "tracked": true }`

**File**: `inc/routes/analytics/link-click.php`

**Used By**: extrachill-blocks plugin (Link Page block analytics)

#### 2. Link Page View Tracking

**Endpoint**: `POST /wp-json/extrachill/v1/analytics/link-page`

**Purpose**: Track page views for artist link pages with authentication.

**Parameters**:
- `artist_id` (int, required) - The artist whose link page was viewed
- `referrer` (string, optional) - HTTP referrer

**Response**: `{ "recorded": true }`

**Permission**: Requires logged-in user

**File**: `inc/routes/analytics/link-page.php`

**Used By**: extrachill-blocks plugin (Link Page analytics)

#### 3. Content View Tracking

**Endpoint**: `POST /wp-json/extrachill/v1/analytics/view-count`

**Purpose**: Track views for any content type across the platform.

**Parameters**:
- `post_id` (int, required) - The post being viewed
- `view_type` (string, optional) - Category of view (e.g., 'embed', 'preview')

**Response**: `{ "recorded": true }`

**File**: `inc/routes/analytics/view-count.php`

**Used By**: Various platform plugins for content tracking

### Artist API

Foundational REST API for artist data management. Provides comprehensive endpoints for profile, links, socials, analytics, and business operations.

#### 4. Artist Core Data

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

#### 5. Artist Social Links

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

#### 6. Artist Link Page Data

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

#### 7. Artist Analytics

**Endpoint**: `GET /wp-json/extrachill/v1/artists/{id}/analytics`

**Purpose**: Retrieve link page performance analytics with configurable date range.

**Parameters**:
- `date_range` (int, optional) - Number of days to analyze (default: 30)

**Response**:
```json
{
  "artist_id": 123,
  "date_range": 30,
  "total_views": 1250,
  "total_clicks": 342,
  "top_links": [
    {"url": "https://spotify.com/...", "clicks": 156},
    {"url": "https://instagram.com/...", "clicks": 98}
  ]
}
```

**Permission**: `ec_can_manage_artist()`

**File**: `inc/routes/artist/analytics.php`

**Notes**:
- Replaces legacy `/analytics/link-page` endpoint with artist-centric routing
- Uses filter hook `extrachill_get_link_page_analytics` for analytics data retrieval

#### 8. Artist Permissions Check

**Endpoint**: `GET /wp-json/extrachill/v1/artist/permissions`

**Purpose**: Check if current user can manage an artist profile.

**Parameters**:
- `artist_id` (int, required) - Artist profile ID to check permissions against

**Response**:
```json
{
  "can_manage": true,
  "artist_id": 123
}
```

**File**: `inc/routes/artist/permissions.php`

**Notes**:
- Returns boolean `can_manage` property
- Accessible to any logged-in user (permission check returns user status)

#### 9. Artist Roster Invite

**Endpoint**: `POST /wp-json/extrachill/v1/artist/roster/invite`

**Purpose**: Invite members to an artist roster for collaborative management.

**Parameters**:
- `artist_id` (int, required) - Artist profile ID
- `email` (string, required) - Email of person to invite

**Response**:
```json
{
  "success": true,
  "message": "Invitation sent successfully"
}
```

**Permission**: User must be artist owner

**File**: `inc/routes/artist/roster.php`

**Notes**:
- Fires filter hook for invitation handling by consuming plugins

#### 10. Artist Subscribers List

**Endpoint**: `GET /wp-json/extrachill/v1/artist/subscribers`

**Purpose**: Retrieve paginated list of artist subscribers.

**Parameters**:
- `artist_id` (int, required) - Artist profile ID
- `page` (int, optional) - Page number (default: 1)
- `per_page` (int, optional) - Results per page (default: 20)

**Response**:
```json
{
  "subscribers": [
    {"id": 1, "email": "fan@example.com", "subscribed_date": "2025-01-15"},
    {"id": 2, "email": "another@example.com", "subscribed_date": "2025-01-14"}
  ],
  "total": 45,
  "page": 1,
  "per_page": 20
}
```

**Permission**: User must be artist owner

**File**: `inc/routes/artist/subscribers.php`

#### 11. Artist Subscribers Export

**Endpoint**: `GET /wp-json/extrachill/v1/artist/subscribers/export`

**Purpose**: Export subscriber list as CSV for email marketing integration.

**Parameters**:
- `artist_id` (int, required) - Artist profile ID

**Response**: CSV file download

**Permission**: User must be artist owner

**File**: `inc/routes/artist/subscribers.php`

#### 12. Artist Subscribe (Public)

**Endpoint**: `POST /wp-json/extrachill/v1/artist/subscribe`

**Purpose**: Allow fans to subscribe to artist updates from public link pages.

**Parameters**:
- `artist_id` (int, required) - Artist profile ID
- `email` (string, required) - Subscriber email address

**Response**:
```json
{
  "success": true,
  "message": "Thank you for subscribing!"
}
```

**Permission**: Public (no authentication required)

**File**: `inc/routes/artist/subscribe.php`

**Notes**:
- Fires `extrachill_artist_subscribe` filter for consuming plugins to handle storage

### Block Generators (AI)

#### 13. Band Name Generator

**Endpoint**: `POST /wp-json/extrachill/v1/blocks/band-name`

**Purpose**: Generate band name suggestions using AI.

**Parameters**:
- `input` (string, required) - Prompt or seed text for name generation
- `genre` (string, optional) - Music genre for context-aware generation
- `number_of_words` (int, optional) - Word count preference

**Response**: AI-generated band names

**Permission**: Public

**File**: `inc/routes/blocks/band-name.php`

**Used By**: extrachill-blocks plugin (Band Name Generator block)

#### 14. Rapper Name Generator

**Endpoint**: `POST /wp-json/extrachill/v1/blocks/rapper-name`

**Purpose**: Generate rapper name suggestions using AI.

**Parameters**:
- `input` (string, required) - Prompt or seed text
- `gender` (string, optional) - Gender for name style
- `style` (string, optional) - Rap style preference

**Response**: AI-generated rapper names

**Permission**: Public

**File**: `inc/routes/blocks/rapper-name.php`

**Used By**: extrachill-blocks plugin (Rapper Name Generator block)

#### 15. AI Adventure Story Generation

**Endpoint**: `POST /wp-json/extrachill/v1/blocks/ai-adventure`

**Purpose**: Generate AI-powered adventure story segments with branching narratives.

**Parameters**:
- `isIntroduction` (boolean, optional) - Whether to trigger introduction flow
- `characterName` (string, optional) - Player character name
- `adventureTitle` (string, optional) - Story title
- `playerInput` (string, optional) - Latest user choice/action
- `storyProgression` (array, optional) - Prior narrative segments
- `conversationHistory` (array, optional) - Dialogue history
- Additional context parameters for story state management

**Response**:
```json
{
  "narrative": "Story prose or empty when progressing to next step",
  "nextStepId": "trigger destination id or null"
}
```

**Permission**: Public

**File**: `inc/routes/blocks/ai-adventure.php`

**Used By**: extrachill-blocks plugin (AI Adventure block)

**Dependencies**: extrachill-ai-client (network-activated)

### Image Voting Block

#### 16. Get Image Voting Results

**Endpoint**: `GET /wp-json/extrachill/v1/blocks/image-voting/vote-count/{post_id}/{instance_id}`

**Purpose**: Retrieve vote counts for image voting block instances.

**Parameters**:
- `post_id` (int, required) - WordPress post ID containing the block
- `instance_id` (string, required) - Unique block instance identifier

**Response**: Vote counts per image option

**File**: `inc/routes/blocks/image-voting.php`

**Used By**: extrachill-blocks plugin (Image Voting block)

#### 17. Vote on Images

**Endpoint**: `POST /wp-json/extrachill/v1/blocks/image-voting/vote`

**Purpose**: Cast a vote in an image voting block instance.

**Parameters**:
- `post_id` (int, required) - Post containing the block
- `instance_id` (string, required) - Block instance ID
- `image_id` (string, required) - Image option being voted for

**Response**: Updated vote totals for that instance

**Permission**: Public (anonymous voting supported)

**File**: `inc/routes/blocks/image-voting-vote.php`

**Used By**: extrachill-blocks plugin (Image Voting block)

### Chat Endpoints

#### 18. Send Chat Message

**Endpoint**: `POST /wp-json/extrachill/v1/chat/message`

**Purpose**: Send a message to the AI chat and receive a response.

**Parameters**:
- `message` (string, required) - User message content

**Response**:
```json
{
  "response": "AI response text",
  "conversation_id": "abc123"
}
```

**Permission**: Requires logged-in user

**File**: `inc/routes/chat/message.php`

**Used By**: extrachill-chat plugin for AI chat functionality

#### 19. Clear Chat History

**Endpoint**: `DELETE /wp-json/extrachill/v1/chat/history`

**Purpose**: Clear conversation history for the current user.

**Parameters**: None

**Response**:
```json
{
  "cleared": true,
  "message": "Chat history cleared"
}
```

**Permission**: Requires logged-in user

**File**: `inc/routes/chat/history.php`

**Used By**: extrachill-chat plugin (Chat settings)

### Community Endpoints

#### 20. User Search (Mentions)

**Endpoint**: `GET /wp-json/extrachill/v1/users/search`

**Purpose**: Search users for @mentions in community posts and comments.

**Parameters**:
- `search` (string, required) - Search term for username/display name

**Response**:
```json
[
  {"username": "chris", "slug": "chris"},
  {"username": "chrissy", "slug": "chrissy"}
]
```

**File**: `inc/routes/community/user-mentions.php`

**Used By**: extrachill-community plugin for @mention functionality

#### 21. Community Upvote

**Endpoint**: `POST /wp-json/extrachill/v1/community/upvote`

**Purpose**: Upvote community forum topics or replies.

**Parameters**:
- `post_id` (int, required) - Topic or reply post ID
- `type` (string, required) - One of: 'topic', 'reply'

**Response**:
```json
{
  "upvoted": true,
  "vote_count": 42
}
```

**Permission**: Requires logged-in user

**File**: `inc/routes/community/upvote.php`

**Used By**: extrachill-community plugin (voting system)

### Admin Endpoints

#### 22. Ad-Free License Grant

**Endpoint**: `POST /wp-json/extrachill/v1/admin/ad-free-license/grant`

**Purpose**: Grant an ad-free license to a user by username or email address.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_identifier` (string, required) - Username or email address of the user to grant license to

**Response** (HTTP 200):
```json
{
  "message": "Ad-free license granted to artist_name",
  "user_id": 123,
  "username": "artist_name",
  "email": "artist@example.com"
}
```

**Error Responses**:
- `400` - Missing identifier or invalid request
- `404` - User not found
- `409` - User already has an ad-free license
- `403` - Permission denied (not a network administrator)

**File**: `inc/routes/admin/ad-free-license.php`

**Notes**:
- Accepts either username or email as identifier
- Stores license data as `extrachill_ad_free_purchased` user meta

#### 23. Ad-Free License Revoke

**Endpoint**: `DELETE /wp-json/extrachill/v1/admin/ad-free-license/{user_id}`

**Purpose**: Revoke an ad-free license from a user.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_id` (integer, required) - The user ID to revoke license from

**Response** (HTTP 200):
```json
{
  "message": "Ad-free license revoked for artist_name",
  "user_id": 123,
  "username": "artist_name"
}
```

**Error Responses**:
- `400` - Missing user ID
- `404` - User not found or user doesn't have an ad-free license
- `403` - Permission denied (not a network administrator)

**File**: `inc/routes/admin/ad-free-license.php`

#### 24. Team Members Sync

**Endpoint**: `POST /wp-json/extrachill/v1/admin/team-members/sync`

**Purpose**: Synchronize team member status for all network users based on main site account presence.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "total_users": 45,
  "users_updated": 12,
  "users_skipped_override": 3,
  "users_with_main_site_account": 15
}
```

**File**: `inc/routes/admin/team-members.php`

**Notes**:
- Automatically detects users with main site accounts
- Respects manual override status
- Updates `extrachill_team` meta

#### 25. Manage Team Member Status

**Endpoint**: `PUT /wp-json/extrachill/v1/admin/team-members/{user_id}`

**Purpose**: Manually manage team member status for a specific user.

**Permission**: Requires `manage_options` capability (network administrators only)

**Parameters**:
- `user_id` (integer, required) - The user ID to manage
- `action` (string, required) - One of: `force_add`, `force_remove`, `reset_auto`

**Response** (HTTP 200):
```json
{
  "message": "User forced to team member.",
  "user_id": 123,
  "is_team_member": true,
  "source": "Manual: Add"
}
```

**File**: `inc/routes/admin/team-members.php`

**Notes**:
- `force_add`: User set as team member with manual override
- `force_remove`: User set as non-team member with manual override
- `reset_auto`: User status determined by automatic sync logic

### User Management Endpoints

#### 26. User Profile

**Endpoint**: `GET /wp-json/extrachill/v1/users/{id}`

**Purpose**: Retrieve comprehensive user profile data with permission-based field visibility.

**Permission**: User must be logged in. Can view own profile or another user's limited public profile.

**Parameters**:
- `id` (integer, required) - The user ID to retrieve

**Response** (HTTP 200):
```json
{
  "id": 123,
  "display_name": "Chris Huber",
  "username": "chris",
  "slug": "chris",
  "avatar_url": "https://...",
  "profile_url": "https://...",
  "is_team_member": true,
  "last_active": 1704067200,
  "email": "chris@example.com",
  "has_ad_free_license": true,
  "is_artist": true,
  "is_professional": false,
  "can_create_artists": true,
  "artist_count": 5,
  "registered": "2024-01-01T12:00:00+00:00"
}
```

**File**: `inc/routes/users/users.php`

**Notes**:
- Full data (email, license status, artist count) only for own profile or admins
- Public fields (name, avatar, profile URL) visible to all logged-in users

#### 27. User Search

**Endpoint**: `GET /wp-json/extrachill/v1/users/search`

**Purpose**: Find users by search term for mentions, autocomplete, or admin relationship management.

**Permission**: 
- `mentions` context: Public access
- `admin` context: Requires `manage_options` capability

**Parameters**:
- `term` (string, required) - Search query term
- `context` (string, optional) - Search context: `mentions` (default) or `admin`

**Response - Mentions Context** (HTTP 200):
```json
[
  {
    "id": 1,
    "username": "chris",
    "slug": "chris"
  }
]
```

**Response - Admin Context** (HTTP 200):
```json
[
  {
    "id": 1,
    "display_name": "Chris Huber",
    "username": "chris",
    "email": "chris@example.com",
    "avatar_url": "https://..."
  }
]
```

**File**: `inc/routes/users/search.php`

**Notes**:
- Mentions context: Lightweight response for @mention autocomplete
- Admin context: Full user data for relationship management

#### 28. User Artists

**Endpoint**: `GET/POST/DELETE /wp-json/extrachill/v1/users/{id}/artists`

**Purpose**: Manage the relationship between users and artist profiles they manage.

**GET Response** (HTTP 200):
```json
[
  {
    "id": 456,
    "name": "The Cool Band",
    "slug": "cool-band",
    "profile_image_url": "https://..."
  }
]
```

**POST Parameters** (admin only):
- `artist_id` (integer, required) - The artist profile post ID to add

**POST Response** (HTTP 200):
```json
{
  "success": true,
  "message": "Artist relationship added.",
  "user_id": 123,
  "artist_id": 456
}
```

**DELETE Endpoint**: `/wp-json/extrachill/v1/users/{id}/artists/{artist_id}`

**Permission**: 
- GET: User can view own artists or admin can view any user's artists
- POST/DELETE: Admin only

**File**: `inc/routes/users/artists.php`

### Documentation Endpoints

#### 29. Documentation Info

**Endpoint**: `GET /wp-json/extrachill/v1/docs-info`

**Purpose**: Retrieve metadata about platform features for documentation generation and feature discovery.

**Permission**: Public access (no authentication required)

**Parameters**:
- `feature` (string, optional) - Limit response to specific feature key (e.g., 'events')

**Response** (HTTP 200):
```json
{
  "features": {
    "events": {
      "site": {
        "blog_id": 7,
        "domain": "events.extrachill.com",
        "path": "/"
      },
      "post_type": "datamachine_events",
      "taxonomies": [...]
    }
  },
  "generated_at": "2024-01-15T12:00:00+00:00"
}
```

**File**: `inc/routes/docs/docs-info.php`

**Notes**:
- Returns all available features if no specific feature requested
- Provides dynamic taxonomy counts and structure
- Used by documentation agents for auto-generation

#### 30. Documentation Sync

**Endpoint**: `POST /wp-json/extrachill/v1/sync/doc`

**Purpose**: Sync documentation from source .md files to documentation platform.

**Permission**: Requires `edit_posts` capability

**Parameters**:
- `source_file` (string, required) - Source file path
- `title` (string, required) - Documentation title
- `content` (string, required) - Documentation content
- `platform_slug` (string, required) - Platform identifier
- `slug` (string, required) - Documentation page slug
- `excerpt` (string, optional) - Short description
- `filesize` (integer, required) - Source file size
- `timestamp` (string, required) - File timestamp
- `force` (boolean, optional) - Force update if already exists

**File**: `inc/routes/docs-sync-routes.php`

**Notes**:
- Handled by docs-sync controller
- Supports force syncing for updates

### Event Submissions

#### 31. Event Submission Flow Proxy

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
2. Sanitizes submission payload and (optionally) stores the flyer in Data Machine's `FileStorage`
3. Creates a Data Machine job + merges submission metadata via `datamachine_merge_engine_data()`
4. Queues `datamachine_run_flow_now` through Action Scheduler
5. Fires `extrachill_event_submission` action with submission + job context for downstream automation

**File**: `inc/routes/events/event-submissions.php`

**Used By**: `extrachill-events` plugin's Event Submission block + front-end form handlers

### Media Management

#### 32. Unified Media Upload

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

### Newsletter

#### 33. Newsletter Subscription

**Endpoint**: `POST /wp-json/extrachill/v1/newsletter/subscription`

**Purpose**: Subscribe users to newsletter mailing list.

**Parameters**:
- `email` (string, required) - Subscriber email address

**Response**:
```json
{
  "subscribed": true,
  "message": "Thank you for subscribing to our newsletter!"
}
```

**Permission**: Public

**File**: `inc/routes/newsletter/subscription.php`

**Used By**: extrachill-newsletter plugin

### Shop (WooCommerce)

#### 34. Shop Products CRUD

**Endpoint**: 
- `GET /wp-json/extrachill/v1/shop/products` - List user's artist products
- `POST /wp-json/extrachill/v1/shop/products` - Create product
- `GET /wp-json/extrachill/v1/shop/products/{id}` - Get single product
- `PUT /wp-json/extrachill/v1/shop/products/{id}` - Update product
- `DELETE /wp-json/extrachill/v1/shop/products/{id}` - Delete product (trash)

**Purpose**: Complete WooCommerce product CRUD operations for artists.

**GET Collection Response**:
```json
{
  "products": [
    {
      "id": 456,
      "name": "Album Name",
      "price": "9.99",
      "stock": 100,
      "image_id": 789,
      "artist_id": 123
    }
  ],
  "total": 5,
  "page": 1
}
```

**Permission**: User must have artist status to create/update/delete

**File**: `inc/routes/shop/products.php`

**Notes**:
- Products created on Blog ID 3 (shop.extrachill.com)
- Linked to artist profiles via `_artist_profile_id` meta
- Comprehensive image and gallery management support
- Stock quantity and sale price support

#### 35. Stripe Connect Management

**Endpoint**: 
- `GET /wp-json/extrachill/v1/shop/stripe` - Get Stripe connection status
- `POST /wp-json/extrachill/v1/shop/stripe/connect` - Connect Stripe account
- `DELETE /wp-json/extrachill/v1/shop/stripe/disconnect` - Disconnect Stripe account
- `POST /wp-json/extrachill/v1/shop/stripe/webhook` - Handle Stripe webhooks

**Purpose**: Manage Stripe Connect authentication and payment processing for artist shops.

**Response**:
```json
{
  "connected": true,
  "account_id": "acct_...",
  "email": "artist@example.com"
}
```

**Permission**: Varies by operation

**File**: `inc/routes/shop/stripe-connect.php`

**Used By**: extrachill-shop plugin for payment processing

### Tools

#### 36. QR Code Generator

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
