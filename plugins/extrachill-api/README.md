# ExtraChill API

Network-activated REST API infrastructure for the Extra Chill Platform WordPress multisite network. Centralizes all custom REST endpoints under a single `extrachill/v1` namespace for consistent access across web, mobile, and AI clients.

## Overview

The ExtraChill API plugin provides a centralized, versioned REST API infrastructure that replaces legacy admin-ajax.php handlers with modern WordPress REST API endpoints. It uses automatic route discovery to modularly organize endpoints by feature area while maintaining a consistent API surface across the entire multisite network.

## Features

- **Network-Wide Activation**: All endpoints available on every site in the multisite network
- **Automatic Route Discovery**: Recursively loads route files from `inc/routes/` directory
- **Versioned Namespace**: All endpoints under `extrachill/v1` for future API evolution
- **Modular Organization**: Routes organized by feature (`blocks/`, `community/`, etc.)
- **WordPress REST API Standards**: Full compliance with WordPress REST API conventions
- **Security First**: Nonce verification, permission callbacks, input validation on all endpoints

## Current Endpoints

The plugin provides 36 endpoints across 13 feature categories, all under the `extrachill/v1` namespace:

### Analytics Endpoints (3)
- `POST /analytics/link-click` - Track link page clicks
- `POST /analytics/link-page` - Track link page views (authenticated)
- `POST /analytics/view-count` - Track content views

### Artist API (9)
- `GET/PUT /artists/{id}` - Core artist profile data
- `GET/PUT /artists/{id}/socials` - Social media links
- `GET/PUT /artists/{id}/links` - Link page data
- `GET /artists/{id}/analytics` - Link page analytics
- `GET /artist/permissions` - Check artist permissions
- `POST /artist/roster/invite` - Invite roster members
- `GET /artist/subscribers` - List subscribers with pagination
- `GET /artist/subscribers/export` - Export subscribers as CSV
- `POST /artist/subscribe` - Public subscription signup

### Block Generators (3)
- `POST /blocks/band-name` - AI band name generation
- `POST /blocks/rapper-name` - AI rapper name generation
- `POST /blocks/ai-adventure` - AI adventure story generation

### Image Voting (2)
- `GET /blocks/image-voting/vote-count/{post_id}/{instance_id}` - Get vote counts
- `POST /blocks/image-voting/vote` - Cast a vote

### Chat Endpoints (2)
- `POST /chat/message` - Send chat message (authenticated)
- `DELETE /chat/history` - Clear chat history (authenticated)

### Community Endpoints (2)
- `GET /users/search` - Search users for @mentions
- `POST /community/upvote` - Upvote topics or replies (authenticated)

### Admin Endpoints (4)
- `POST /admin/ad-free-license/grant` - Grant ad-free license
- `DELETE /admin/ad-free-license/{user_id}` - Revoke ad-free license
- `POST /admin/team-members/sync` - Sync team members
- `PUT /admin/team-members/{user_id}` - Manage team member status

### User Management (3)
- `GET /users/{id}` - Get user profile
- `GET /users/search` - Search users (admin context)
- `GET/POST/DELETE /users/{id}/artists` - Manage user-artist relationships

### Documentation (2)
- `GET /docs-info` - Documentation metadata
- `POST /sync/doc` - Sync documentation

### Event Submissions (1)
- `POST /event-submissions` - Submit event with optional flyer

### Media Management (1)
- `POST/DELETE /media` - Upload and manage images

### Newsletter (1)
- `POST /newsletter/subscription` - Subscribe to newsletter

### Shop Integration (2)
- `GET/POST/PUT/DELETE /shop/products` - Product CRUD operations
- `GET/POST/DELETE /shop/stripe` - Stripe Connect management

### Tools (1)
- `POST /tools/qr-code` - Generate QR codes

See [AGENTS.md](AGENTS.md) for complete endpoint documentation with request/response examples.

## Installation

### Requirements
- WordPress 5.0+
- PHP 7.4+
- Extra Chill Platform multisite network

### Setup
1. Upload plugin to `/wp-content/plugins/extrachill-api/`
2. **Network activate** via Network Admin → Plugins
3. Endpoints immediately available on all network sites

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
├── AGENTS.md                  # Technical documentation for AI agents
├── README.md                  # This file
└── inc/
    ├── auth/
    │   └── extrachill-link-auth.php    # Cross-domain authentication
    └── routes/
        ├── admin/                      # Network admin endpoints
        │   ├── ad-free-license.php
        │   └── team-members.php
        ├── analytics/                  # Analytics tracking endpoints
        │   ├── link-click.php
        │   ├── link-page.php
        │   └── view-count.php
        ├── artist/                     # Artist API endpoints
        │   ├── analytics.php
        │   ├── artist.php
        │   ├── links.php
        │   ├── permissions.php
        │   ├── roster.php
        │   ├── socials.php
        │   ├── subscribe.php
        │   └── subscribers.php
        ├── blocks/                     # AI block generators
        │   ├── ai-adventure.php
        │   ├── band-name.php
        │   ├── image-voting.php
        │   ├── image-voting-vote.php
        │   └── rapper-name.php
        ├── chat/                       # Chat functionality
        │   ├── history.php
        │   └── message.php
        ├── community/                  # Community features
        │   ├── upvote.php
        │   └── user-mentions.php
        ├── docs/                       # Documentation endpoints
        │   └── docs-info.php
        ├── events/                     # Event management
        │   └── event-submissions.php
        ├── media/                      # Media upload
        │   └── upload.php
        ├── newsletter/                 # Newsletter
        │   └── subscription.php
        ├── shop/                       # WooCommerce integration
        │   ├── products.php
        │   └── stripe-connect.php
        ├── tools/                      # Utilities
        │   └── qr-code.php
        └── users/                      # User management
            ├── artists.php
            ├── search.php
            └── users.php
```

## Development

### Local Setup
```bash
cd extrachill-plugins/extrachill-api

# Install dependencies
composer install

# Run tests
composer test

# PHP linting
composer run lint:php
```

### Production Build
```bash
# Create optimized production package
./build.sh

# Output: build/extrachill-api.zip
```

### Testing Endpoints
```bash
# List all registered routes
wp rest list

# Test endpoint with curl
curl -X GET "http://site.local/wp-json/extrachill/v1/users/search?search=test"
```

## Integration

### Current Plugin Integrations

**extrachill-blocks**:
- Image voting vote counts and voting
- AI Adventure story generation
- Band/Rapper name generators

**extrachill-community**:
- User search for @mentions
- Community upvoting

**extrachill-events**:
- Event submission block posts to the `/event-submissions` route

**extrachill-users**:
- Avatar upload via media endpoint
- User profile and search functionality

**extrachill-shop**:
- Product CRUD operations
- Stripe Connect management

### Planned Migrations

Future endpoints to replace admin-ajax.php handlers:
1. Artist platform analytics tracking
2. Newsletter subscription management
3. Community voting and reactions
4. Admin tools background operations

## Security

All endpoints implement WordPress REST API security standards:
- **Nonce Verification**: WordPress REST API nonce system
- **Permission Callbacks**: User capability checks
- **Input Validation**: Parameter validation callbacks
- **Sanitization**: All user input sanitized
- **Output Escaping**: Responses via `rest_ensure_response()`

## Performance

- **Caching**: Endpoints return fresh data by default
- **Query Optimization**: Prepared statements and pagination
- **Network-Wide**: Single plugin serves all sites efficiently
- **Automatic Discovery**: Minimal overhead with RecursiveIteratorIterator

## Dependencies

**Required**:
- WordPress 5.0+ (REST API support)
- PHP 7.4+

**Recommended**:
- extrachill-multisite (network-activated foundation)

**Optional**:
- extrachill-ai-client (for AI Adventure endpoint)
- extrachill-blocks (primary endpoint consumer)
- extrachill-community (user search consumer)
- extrachill-events (event submission consumer)
- extrachill-multisite (Turnstile helpers for event submissions)

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

- **[AGENTS.md](AGENTS.md)** - Comprehensive technical documentation for AI agents
- **[Root AGENTS.md](../../AGENTS.md)** - Platform-wide architectural patterns
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
