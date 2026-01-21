# ExtraChill API

Network-activated REST API infrastructure for the Extra Chill Platform WordPress multisite network. Centralizes all custom REST endpoints under a single `extrachill/v1` namespace for consistent access across web, mobile, and AI clients.

## Overview

The ExtraChill API plugin provides a centralized, versioned REST API infrastructure that replaces legacy admin-ajax.php handlers with modern WordPress REST API endpoints. It uses automatic route discovery to modularly organize endpoints by feature area while maintaining a consistent API surface across the entire multisite network.

The plugin also owns the network-wide activity storage table (`{base_prefix}extrachill_activity`) used by the activity feed endpoints.

## Features

- **Network-Wide Activation**: All endpoints available on every site in the multisite network
- **Activity Storage**: Maintains the network-wide `{base_prefix}extrachill_activity` table used by the activity feed endpoints
- **Automatic Route Discovery**: Recursively loads route files from `inc/routes/`
- **Versioned Namespace**: All endpoints are under `extrachill/v1`
- **Modular Organization**: Routes are organized by feature (`admin/`, `artists/`, `shop/`, etc.)
- **Security**: Endpoints use permission callbacks, validation, and sanitization (per-route implementation)
- **Activity Storage**: Maintains a network-wide `{base_prefix}extrachill_activity` table used by activity endpoints

## Current Endpoints

The plugin ships route files under `inc/routes/` (loaded recursively) and registers endpoints under the `extrachill/v1` namespace:

### Authentication Endpoints (7)
- `POST /auth/browser-handoff` - Browser handoff for cross-device auth
- `POST /auth/google` - Google OAuth authentication
- `POST /auth/login` - User login returning access + refresh tokens
- `POST /auth/logout` - Logout and token revocation
- `GET /auth/me` - Get current authenticated user
- `POST /auth/refresh` - Refresh access tokens
- `POST /auth/register` - User registration

### Configuration Endpoints (1)
- `GET /config/oauth` - OAuth provider configuration

### Analytics Endpoints
- `POST /analytics/view` - Async view tracking (increments `ec_post_views`)
- `POST /analytics/click` - Unified click tracking
- `GET /analytics/link-page` - Fetch link page analytics
- `GET /analytics/events` - Query network analytics events (requires `manage_network_options`)
- `GET /analytics/events/summary` - Aggregate network event stats (requires `manage_network_options`)
- `GET /analytics/meta` - Analytics filter metadata (requires `manage_network_options`)
- `POST /analytics/link-page` - Track link page view events

### Artist API (9)
- `GET/PUT /artists/{id}` - Core artist profile data
- `GET/PUT /artists/{id}/socials` - Social media links
- `GET/PUT /artists/{id}/links` - Link page data
- `GET /artists/{id}/analytics` - Link page analytics
- `GET /artists/{id}/permissions` - Check artist permissions
- `GET/POST/DELETE /artists/{id}/roster` - Roster member management
- `GET /artists/{id}/subscribers` - List subscribers with pagination
- `GET /artists/{id}/subscribers/export` - Export subscribers as CSV
- `POST /artists/{id}/subscribe` - Public subscription signup

### Block Generators (3)
- `POST /blog/band-name` - AI band name generation
- `POST /blog/rapper-name` - AI rapper name generation
- `POST /blog/ai-adventure` - AI adventure story generation

### Image Voting (2)
- `GET /blog/image-voting/vote-count/{post_id}/{instance_id}` - Get vote counts
- `POST /blog/image-voting/vote` - Cast a vote

### Chat Endpoints (2)
- `POST /chat/message` - Send chat message (authenticated)
- `DELETE /chat/history` - Clear chat history (authenticated)

### Community Endpoints (3)
- `GET /users/search` - Search users for @mentions and admin
- `POST /community/upvote` - Upvote topics or replies (authenticated)
- `GET/POST/DELETE /community/drafts` - Manage bbPress drafts (authenticated)

### Contact (1)
- `POST /contact/submit` - Submit contact form with Turnstile verification

### Activity Endpoints (2)
- `GET /activity` - Activity feed with filtering and pagination (authenticated)
- `GET /object` - Object resolver for posts, comments, and artists (authenticated)

### Admin Endpoints (14)
- `GET /admin/artist-access` - List pending artist access requests
- `GET/POST /admin/artist-access/{user_id}/approve` - Approve artist access request
- `POST /admin/artist-access/{user_id}/reject` - Reject artist access request
- `GET /admin/lifetime-membership` - List all lifetime memberships
- `POST /admin/lifetime-membership/grant` - Grant Lifetime Extra Chill Membership (ad-free)
- `DELETE /admin/lifetime-membership/{user_id}` - Revoke Lifetime Extra Chill Membership
- `GET /admin/team-members` - List all team members with search/pagination
- `POST /admin/team-members/sync` - Sync team members
- `PUT /admin/team-members/{user_id}` - Manage team member status
- `POST /admin/taxonomies/sync` - Sync shared taxonomies across sites
- `GET /admin/tag-migration` - List tags for migration searching
- `GET /admin/forum-topics` - List and manage bbPress topics across network
- `GET /admin/forum-topics/{topic_id}` - Manage a single bbPress topic
- `PUT /admin/forum-topics/{topic_id}/status` - Update bbPress topic status
- `GET /admin/404-logger` - Monitor 404 errors for SEO management
- `GET /admin/artist-relationships` - Manage user-artist links

### User Management (5)
- `GET /users/{id}` - Get user profile
- `GET/POST /users/onboarding` - User onboarding flow
- `GET /users/leaderboard` - Get user leaderboard with rankings
- `GET/POST/DELETE /users/{id}/artists` - Manage user-artist relationships
- `GET /users/search` - Find users (multiple contexts)

### Documentation (2)
- `GET /docs-info` - Documentation metadata
- `POST /sync/doc` - Sync documentation

### Event Submissions (1)
- `POST /event-submissions` - Submit event with optional flyer

### Media Management (1)
- `POST /media` - Upload media

### Newsletter (2)
- `POST /newsletter/subscribe` - Subscribe to newsletter
- `POST /newsletter/campaign/push` - Push newsletter to Sendy

### Shop Integration
- `GET/POST/PUT/DELETE /shop/products` - Product CRUD operations
- `GET /shop/orders` - List orders (requires WooCommerce on the shop site)
- `PUT /shop/orders/{id}/status` - Mark shipped (`status=completed`)
- `POST /shop/orders/{id}/refund` - Issue full refund
- `POST/DELETE /shop/products/{id}/images` - Product image management
- `GET/POST/DELETE /shop/stripe` - Stripe Connect management
- `POST /shop/stripe-webhook` - Stripe webhook handler
- `GET/PUT /shop/shipping-address` - Artist shipping from-address management
- `GET/POST /shop/shipping-labels` - Purchase and retrieve shipping labels

### Stream (1)
- `GET /stream/status` - Check streaming status and configuration

### Tools (2)
- `POST /tools/qr-code` - Generate QR codes
- `GET /tools/markdown-export` - Export content as markdown

### SEO Endpoints (5)
- `POST /seo/audit` - Start multisite SEO audit (full or batch mode)
- `POST /seo/audit/continue` - Continue paused batch audit
- `GET /seo/audit/status` - Check audit status and results
- `GET /seo/audit/details` - Get detailed audit results by category with pagination
- `GET /seo/config` - Get SEO audit configuration


See [CLAUDE.md](CLAUDE.md) for architectural patterns and [docs/routes/](docs/routes/) for complete endpoint documentation.

## Usage

### For Plugin Developers

Add new endpoints by creating route files in `inc/routes/`:

```php
<?php
/**
 * Custom endpoint registration
 */

add_action('extrachill_api_register_routes', function() {
    register_rest_route('extrachill/v1', '/my-endpoint', [
        'methods' => 'GET',
        'callback' => 'my_endpoint_callback',
        'permission_callback' => '__return_true',
    ]);
});

function my_endpoint_callback($request) {
    return rest_ensure_response(['status' => 'success']);
}
```

### For Client Developers

Access endpoints using standard WordPress REST API patterns:

**JavaScript**:
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

**PHP**:
```php
$response = wp_remote_get(
    rest_url('extrachill/v1/users/search'),
    ['body' => ['search' => 'username']]
);
```

## Architecture

### Singleton Pattern
The plugin uses a singleton class to manage route discovery and prevent duplicate registrations:

```php
ExtraChill_API_Plugin::get_instance();
```

### Automatic Route Discovery
Routes are automatically loaded using PHP's RecursiveIteratorIterator:
- Scans `inc/routes/` directory recursively
- Loads all PHP files via `require_once`
- Supports nested directory organization
- Routes self-register using WordPress REST API

### Action Hooks
- `extrachill_api_bootstrap` - Initialization during `plugins_loaded`
- `extrachill_api_register_routes` - Route registration during `rest_api_init`

## Directory Structure

```
extrachill-api/
├── extrachill-api.php          # Main plugin file
├── build.sh                    # Production build script (symlink)
├── composer.json               # Dependencies and scripts
├── .buildignore               # Build exclusions
├── CLAUDE.md                  # Technical documentation for AI agents
├── README.md                  # This file
└── inc/
    ├── auth/
    │   └── extrachill-link-auth.php    # Cross-domain authentication helper
    ├── activity/                        # Activity system components
    │   ├── db.php
    │   ├── emitter.php
    │   ├── emitters.php
    │   ├── schema.php
    │   ├── storage.php
    │   ├── taxonomies.php
    │   └── throttle.php
    ├── controllers/
    │   └── class-docs-sync-controller.php
    ├── utils/
    │   ├── bbpress-drafts.php
    │   └── id-generator.php
    └── routes/
        ├── admin/
        │   ├── 404-logger.php
        │   ├── lifetime-membership.php
        │   ├── artist-access.php
        │   ├── artist-relationships.php
        │   ├── forum-topics.php
        │   ├── tags.php
        │   ├── taxonomy-sync.php
        │   └── team-members.php
        ├── activity/
        │   ├── feed.php
        │   └── object.php
        ├── analytics/
        │   ├── click.php
        │   ├── link-page.php
        │   └── view-count.php
        ├── artists/
        │   ├── analytics.php
        │   ├── artist.php
        │   ├── links.php
        │   ├── permissions.php
        │   ├── roster.php
        │   ├── socials.php
        │   ├── subscribe.php
        │   └── subscribers.php
        ├── auth/
        │   ├── browser-handoff.php
        │   ├── google.php
        │   ├── login.php
        │   ├── logout.php
        │   ├── me.php
        │   ├── refresh.php
        │   └── register.php
        ├── blog/
        │   ├── ai-adventure.php
        │   ├── band-name.php
        │   ├── image-voting.php
        │   ├── image-voting-vote.php
        │   └── rapper-name.php
        ├── chat/
        │   ├── history.php
        │   └── message.php
        ├── community/
        │   ├── drafts.php
        │   └── upvote.php
        ├── config/
        │   └── oauth.php
        ├── contact/
        │   └── submit.php
        ├── docs/
        │   └── docs-info.php
        ├── docs-sync-routes.php
        ├── events/
        │   └── event-submissions.php
        ├── media/
        │   └── upload.php
        ├── newsletter/
        │   ├── campaign.php
        │   └── subscription.php
        ├── seo/
        │   ├── audit.php
        │   ├── continue.php
        │   ├── status.php
        │   ├── details.php
        │   └── config.php
        ├── shop/
        │   ├── orders.php
        │   ├── product-images.php
        │   ├── products.php
        │   ├── shipping-address.php
        │   ├── shipping-labels.php
        │   ├── stripe-connect.php
        │   └── stripe-webhook.php
        ├── stream/
        │   └── status.php
        ├── tools/
        │   ├── qr-code.php
        │   └── markdown-export.php
        └── users/
            ├── artists.php
            ├── leaderboard.php
            ├── onboarding.php
            ├── search.php
            └── users.php
```

## Development

### Production Build
```bash
./build.sh
```

### Testing Endpoints
```bash
wp rest list
```

## Integration

### Current Plugin Integrations

**extrachill-blog**:
- Image voting vote counts and voting
- AI Adventure story generation
- Band/Rapper name generators

**extrachill-community**:
- User search for @mentions
- Community upvoting
- Draft management for topics and replies

**extrachill-events**:
- Event submission block posts to the `/event-submissions` route

**extrachill-users**:
- Avatar upload via media endpoint
- User profile and search functionality

**extrachill-shop**:
- Product CRUD operations
- Stripe Connect management

**extrachill-newsletter**:
- Newsletter subscription endpoint
- Campaign push to Sendy

## Security

All endpoints implement WordPress REST API security standards:
- **Nonce Verification**: WordPress REST API nonce system
- **Permission Callbacks**: User capability checks
- **Input Validation**: Parameter validation callbacks
- **Sanitization**: All user input sanitized
- **Output Escaping**: Responses via `rest_ensure_response()`

## Performance

- Route files load once via `require_once` on plugin init
- Per-endpoint performance depends on the underlying query and site context (some endpoints switch blog context)

## Dependencies

**Runtime**:
- WordPress multisite

**Optional integrations**:
- WooCommerce (required by some `/shop/*` routes, in the shop site context)
- `extrachill-multisite` (provides shared multisite helpers used by some routes)
- `extrachill-ai-client` (used by AI generator routes when enabled)

## Hooks

### `extrachill_event_submission`

Fires after a submission is validated, optional flyer stored, and a Data Machine job is queued.

```php
add_action( 'extrachill_event_submission', function( array $submission, array $context ) {
    // $submission contains sanitized form data + optional flyer metadata
    // $context includes flow_id, job_id, action_id, flow_name
} );
```

Use the hook to notify editors, trigger Slack alerts, or log analytics without reimplementing REST validation.

## Documentation

- **[CLAUDE.md](CLAUDE.md)** - Comprehensive technical documentation for AI agents
- **[Root CLAUDE.md](../../CLAUDE.md)** - Platform-wide architectural patterns
- **[WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)** - Official REST API documentation

## Support

For issues, questions, or feature requests related to the Extra Chill Platform:
- **Primary Contact**: Chris Huber - [chubes.net](https://chubes.net)
- **Organization**: [Extra Chill](https://github.com/Extra-Chill)
- **Main Site**: [extrachill.com](https://extrachill.com)

## License

GPL v2 or later

## Changelog

See [docs/CHANGELOG.md](docs/CHANGELOG.md) for full version history.

---

**Part of the Extra Chill Platform** - WordPress multisite ecosystem for music community
