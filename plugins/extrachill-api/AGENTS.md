# ExtraChill API Plugin - Architectural Documentation

Network-activated REST API infrastructure for the Extra Chill Platform multisite network. Provides centralized endpoint management under the `extrachill/v1` namespace for web, mobile, and AI clients.

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
- Supports nested directory organization (`inc/routes/blog/`, `inc/routes/community/`)

**Action Hooks**:
- `extrachill_api_bootstrap` - Fires during `plugins_loaded` for initialization
- `extrachill_api_register_routes` - Fires during `rest_api_init` for route registration

## Directory Structure

```
extrachill-api/
├── extrachill-api.php (Main plugin file with singleton class)
├── inc/
│   └── routes/
│       ├── activity/
│       │   ├── feed.php (Activity feed with filtering)
│       │   └── object.php (Object resolver for posts/comments/artists)
│       ├── admin/
│       │   ├── ad-free-license.php (Ad-free license management)
│       │   ├── artist-access.php (Artist access approval/rejection)
│       │   └── team-members.php (Team member sync and management)
│       ├── analytics/
│       │   ├── link-click.php (Track link page clicks)
│       │   ├── link-page.php (Track link page views)
│       │   └── view-count.php (Track content views)
│       ├── artists/
│       │   ├── analytics.php (Artist link page analytics)
│       │   ├── artist.php (Core artist data CRUD)
│       │   ├── links.php (Link page data management)
│       │   ├── socials.php (Social links management)
│       │   ├── permissions.php (Permission check)
│       │   ├── roster.php (Roster invite management)
│       │   ├── subscribe.php (Subscription signup)
│       │   └── subscribers.php (Subscriber management)
│       ├── auth/
│       │   ├── google.php (Google OAuth authentication)
│       │   ├── login.php (User authentication with JWT tokens)
│       │   ├── logout.php (Device token revocation)
│       │   ├── me.php (Current user data)
│       │   ├── refresh.php (Token refresh for continued sessions)
│       │   └── register.php (User registration with token generation)
│       ├── blog/
│       │   ├── ai-adventure.php (AI adventure story generation)
│       │   ├── band-name.php (Band name generator)
│       │   ├── image-voting.php (Image voting vote counts)
│       │   ├── image-voting-vote.php (Vote on images)
│       │   └── rapper-name.php (Rapper name generator)
│       ├── chat/
│       │   ├── history.php (Clear chat history)
│       │   └── message.php (Send/receive chat messages)
│       ├── community/
│       │   ├── drafts.php (bbPress draft management)
│       │   └── upvote.php (Topic/reply upvotes)
│       ├── config/
│       │   └── oauth.php (OAuth provider configuration)
│       ├── contact/
│       │   └── submit.php (Contact form submission)
│       ├── docs/
│       │   └── docs-info.php (Documentation endpoint info)
│       ├── events/
│       │   └── event-submissions.php (Event submission proxy)
│       ├── media/
│       │   └── upload.php (Unified media upload)
│       ├── newsletter/
│       │   ├── campaign.php (Newsletter campaign push to Sendy)
│       │   └── subscription.php (Newsletter subscription)
│       ├── shop/
│       │   ├── orders.php (Artist order management)
│       │   ├── product-images.php (Product image upload/delete)
│       │   ├── products.php (WooCommerce product CRUD)
│       │   ├── stripe-connect.php (Stripe Connect management)
│       │   └── stripe-webhook.php (Stripe webhook handler)
│       ├── tools/
│       │   └── qr-code.php (QR code generator)
│       ├── stream/
│       │   └── status.php (Stream status endpoint)
│       └── users/
│           ├── artists.php (User artist relationships)
│           ├── leaderboard.php (User leaderboard)
│           ├── onboarding.php (User onboarding flow)
│           ├── search.php (User search endpoint)
│           └── users.php (User profile endpoint)
```

## Endpoint Categories

All endpoints are under the `extrachill/v1` namespace.

### Authentication Endpoints (6)
- `POST /auth/login` - User login with JWT tokens
- `POST /auth/refresh` - Token refresh for continued sessions
- `GET /auth/me` - Current authenticated user
- `POST /auth/logout` - Logout and token revocation
- `POST /auth/register` - User registration
- `POST /auth/google` - Google OAuth authentication

**Documentation**: [docs/routes/auth/](../extrachill-plugins/extrachill-api/docs/routes/auth/)

### Configuration Endpoints (1)
- `GET /config/oauth` - OAuth provider configuration

**Documentation**: [docs/routes/config/oauth.md](../extrachill-plugins/extrachill-api/docs/routes/config/oauth.md)

### Analytics Endpoints (3)
- `POST /analytics/link-click` - Track link page clicks
- `POST /analytics/link-page` - Track link page views (authenticated)
- `POST /analytics/view-count` - Track content views

**Documentation**: [docs/routes/analytics/](../extrachill-plugins/extrachill-api/docs/routes/analytics/)

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

**Documentation**: [docs/routes/artists/](../extrachill-plugins/extrachill-api/docs/routes/artists/)

### Block Generators (3)
- `POST /blog/band-name` - AI band name generation
- `POST /blog/rapper-name` - AI rapper name generation
- `POST /blog/ai-adventure` - AI adventure story generation

**Documentation**: [docs/routes/blog/](../extrachill-plugins/extrachill-api/docs/routes/blog/)

### Image Voting (2)
- `GET /blog/image-voting/vote-count/{post_id}/{instance_id}` - Get vote counts
- `POST /blog/image-voting/vote` - Cast a vote

**Documentation**: [docs/routes/blog/image-voting-vote.md](../extrachill-plugins/extrachill-api/docs/routes/blog/image-voting-vote.md)

### Chat Endpoints (2)
- `POST /chat/message` - Send chat message (authenticated)
- `DELETE /chat/history` - Clear chat history (authenticated)

**Documentation**: [docs/routes/chat/](../extrachill-plugins/extrachill-api/docs/routes/chat/)

### Community Endpoints (3)
- `GET /users/search` - Search users for @mentions and admin
- `POST /community/upvote` - Upvote topics or replies (authenticated)
- `GET/POST/DELETE /community/drafts` - Manage bbPress drafts (authenticated)

**Documentation**: [docs/routes/community/](../extrachill-plugins/extrachill-api/docs/routes/community/)

### Contact (1)
- `POST /contact/submit` - Submit contact form with Turnstile verification

**Documentation**: [docs/routes/contact/submit.md](../extrachill-plugins/extrachill-api/docs/routes/contact/submit.md)

### Activity Endpoints (2)
- `GET /activity` - Activity feed with filtering and pagination (authenticated)
- `GET /object` - Object resolver for posts, comments, and artists (authenticated)

**Documentation**: [docs/routes/activity/](../extrachill-plugins/extrachill-api/docs/routes/activity/)

### Admin Endpoints (7)
- `GET/POST /admin/artist-access/{user_id}/approve` - Approve artist access request
- `POST /admin/artist-access/{user_id}/reject` - Reject artist access request
- `POST /admin/ad-free-license/grant` - Grant ad-free license
- `DELETE /admin/ad-free-license/{user_id}` - Revoke ad-free license
- `POST /admin/team-members/sync` - Sync team members
- `PUT /admin/team-members/{user_id}` - Manage team member status

**Documentation**: [docs/routes/admin/](../extrachill-plugins/extrachill-api/docs/routes/admin/)

### User Management (5)
- `GET /users/{id}` - Get user profile
- `GET/POST /users/onboarding` - User onboarding flow
- `GET /users/leaderboard` - Get user leaderboard with rankings
- `GET/POST/DELETE /users/{id}/artists` - Manage user-artist relationships
- `GET /users/search` - Find users (multiple contexts)

**Documentation**: [docs/routes/users/](../extrachill-plugins/extrachill-api/docs/routes/users/)

### Documentation (2)
- `GET /docs-info` - Documentation metadata
- `POST /sync/doc` - Sync documentation

**Documentation**: [docs/routes/docs/](../extrachill-plugins/extrachill-api/docs/routes/docs/)

### Event Submissions (1)
- `POST /event-submissions` - Submit event with optional flyer

**Documentation**: [docs/routes/events/event-submissions.md](../extrachill-plugins/extrachill-api/docs/routes/events/event-submissions.md)

### Media Management (1)
- `POST/DELETE /media` - Upload and manage images

**Documentation**: [docs/routes/media/upload.md](../extrachill-plugins/extrachill-api/docs/routes/media/upload.md)

### Newsletter (2)
- `POST /newsletter/subscription` - Subscribe to newsletter
- `POST /newsletter/campaign/push` - Push newsletter to Sendy

**Documentation**: [docs/routes/newsletter/](../extrachill-plugins/extrachill-api/docs/routes/newsletter/)

### Shop Integration (5)
- `GET/POST/PUT/DELETE /shop/products` - Product CRUD operations
- `GET/POST/DELETE /shop/orders` - Artist order management and fulfillment
- `POST/DELETE /shop/products/{id}/images` - Product image management
- `GET/POST/DELETE /shop/stripe` - Stripe Connect management
- `POST /shop/stripe-webhook` - Stripe webhook handler

**Documentation**: [docs/routes/shop/](../extrachill-plugins/extrachill-api/docs/routes/shop/)

### Stream (1)
- `GET /stream/status` - Check streaming status and configuration

**Documentation**: [docs/routes/stream/status.md](../extrachill-plugins/extrachill-api/docs/routes/stream/status.md)

### Tools (1)
- `POST /tools/qr-code` - Generate QR codes

**Documentation**: [docs/routes/tools/qr-code.md](../extrachill-plugins/extrachill-api/docs/routes/tools/qr-code.md)

## Plugin Integration Patterns

### Current Consumers

**extrachill-blocks**:
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

**extrachill-app** (Mobile):
- Authentication endpoints (login, refresh, logout, me)
- Activity feed endpoint

### Cross-Plugin Communication

**Function Existence Checks**:
```php
if (function_exists('ec_get_user_profile_url')) {
    $profile_url = ec_get_user_profile_url($user_id);
}
```

**Filter-Based Registration**:
```php
// Plugins register activity types
add_filter('extrachill_activity_types', function($types) {
    $types[] = 'post_published';
    return $types;
});
```

## Security Patterns

All endpoints implement WordPress REST API security standards:
- **Nonce Verification**: WordPress REST API nonce system
- **Permission Callbacks**: User capability checks
- **Input Validation**: Parameter validation callbacks
- **Sanitization**: All user input sanitized
- **Output Escaping**: Responses via `rest_ensure_response()`

## Performance Patterns

- **Caching**: Endpoints return fresh data by default
- **Query Optimization**: Prepared statements and pagination
- **Network-Wide**: Single plugin serves all sites efficiently
- **Automatic Discovery**: Minimal overhead with RecursiveIteratorIterator

## Mobile App Integration

The mobile app (extrachill-app) consumes the following endpoints:

**Authentication**:
- `POST /auth/login` - Initial authentication with device tracking
- `POST /auth/refresh` - Token rotation for continued sessions
- `GET /auth/me` - Verify authentication state and get user data
- `POST /auth/logout` - Revoke device session

**Activity**:
- `GET /activity` - Paginated activity feed with filtering

**Mobile-Specific Considerations**:
- All auth endpoints require `device_id` (UUID v4) parameter
- JWT tokens stored securely using Expo SecureStore
- Automatic token refresh when access token expires
- Device tracking supports multi-device session management

## Documentation References

- **[README.md](README.md)** - GitHub standard format overview
- **[docs/routes/](docs/routes/)** - Detailed endpoint specifications
- **[Root AGENTS.md](../../AGENTS.md)** - Platform-wide architectural patterns
- **[WordPress REST API Handbook](https://developer.wordpress.org/rest-api/)** - Official REST API documentation
