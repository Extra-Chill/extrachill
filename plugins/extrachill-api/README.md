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

### User Search (Community)
```
GET /wp-json/extrachill/v1/users/search?search={term}
```
Search users for @mentions in community posts and comments.

### Image Voting (Blocks)
```
GET /wp-json/extrachill/v1/image-voting/vote-count/{post_id}/{instance_id}
```
Retrieve vote counts for image voting block instances.

### AI Adventure (Blocks)
```
POST /wp-json/extrachill/v1/ai-adventure
```
Generate AI-powered adventure story segments.

### Event Submissions (Events)
```
POST /wp-json/extrachill/v1/event-submissions
```
Validate public event submissions, verify Cloudflare Turnstile tokens, store optional flyers via Data Machine file storage, and queue the configured Data Machine flow. Returns `{ success: true, job_id }` when the flow is scheduled.

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
└── inc/
    └── routes/
    ├── blocks/
    │   ├── ai-adventure.php
    │   └── image-voting.php
    ├── community/
    │   └── user-mentions.php
    └── events/
        └── event-submissions.php
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
- Image voting vote counts
- AI Adventure story generation

**extrachill-community**:
- User search for @mentions

**extrachill-events**:
- Event submission block posts to the new `/event-submissions` route

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

### 0.1.2
- Added QR code generator endpoint moved from extrachill-admin-tools plugin
- Added analytics, artist permissions, community features, and user avatar endpoints
- Enhanced event submissions with auto-population for logged-in users
- Simplified newsletter subscriptions by removing Turnstile verification
- Added comprehensive AGENTS.md documentation file
- Added Endroid QR Code library dependency

### 0.1.1
- Added event submissions endpoint with Cloudflare Turnstile validation
- Added extrachill_event_submission action hook
- Updated documentation for new endpoint and hook
- Added docs/CHANGELOG.md as changelog source of truth

### 0.1.0
- Initial release
- Automatic route discovery system
- Community user search endpoint
- Blocks endpoints (image voting, AI adventure)
- Network activation support
- WordPress REST API standards compliance

---

**Part of the Extra Chill Platform** - WordPress multisite ecosystem for music community
