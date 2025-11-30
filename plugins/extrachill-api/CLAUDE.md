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
        ├── blocks/
        │   ├── ai-adventure.php (AI Adventure block endpoint)
        │   ├── image-voting.php (Image voting block endpoint)
        │   └── trivia.php (Trivia block endpoint)
        └── community/
            └── user-mentions.php (User search for mentions)
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

## Integration Patterns

### For Plugin Developers

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
1. Artist platform analytics endpoints
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
- [Root CLAUDE.md - Architectural Patterns](../../CLAUDE.md#architectural-patterns)
- [extrachill-blocks CLAUDE.md](../extrachill-blocks/CLAUDE.md) - Endpoint consumers
- [extrachill-community CLAUDE.md](../extrachill-community/CLAUDE.md) - User search integration
- [extrachill-ai-client CLAUDE.md](../extrachill-ai-client/CLAUDE.md) - AI provider integration

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
