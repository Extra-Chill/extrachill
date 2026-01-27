# Extra Chill Newsletter - Agent Development Guide

## Plugin Overview

**Extra Chill Newsletter** is a comprehensive network-wide newsletter system providing centralized Sendy integration for the Extra Chill Platform. Operating as a network-activated plugin, it delivers subscription forms, list management, and campaign tracking across all 11 active sites while maintaining specialized functionality on newsletter.extrachill.com (Blog ID 9).

**Key Purpose**: Bridge WordPress multisite network to Sendy email marketing platform, providing unified subscription workflows while allowing per-site customization through filters and hooks.

**Version**: 0.2.6  
**Status**: Production active (v0.2.6 includes email template overhaul, REST API migration, and theme integration fix)  
**Network-Activated**: Yes  
**Text Domain**: extrachill-newsletter

### Core Responsibilities
- **Sendy Integration**: Centralized API key management and subscription routing to Sendy lists
- **Subscription Forms**: Display contextual subscription forms across all sites (homepage, navigation, content, archive)
- **Newsletter Management**: Custom post type for newsletter content on newsletter.extrachill.com only
- **Admin Interface**: Settings page for Sendy configuration and list ID mapping
- **Cross-Site Coordination**: Network-wide subscription handling with site-specific form contexts

## Plugin Architecture

### Design Pattern

**Network-Wide with Site-Specific Features**:
- Core subscription functionality available on all sites (forms, API, hooks)
- Newsletter post type and admin UI only loaded on newsletter.extrachill.com
- Settings stored in network options accessible by all sites
- Form rendering delegated to theme via hooks, plugins provide display logic

**Loading Sequence**:
1. Core files load immediately (assets, sendy-api, email-template)
2. Network-wide hook integrations load (forms, homepage, post-meta, sidebar, breadcrumbs)
3. Newsletter-site-only features load on plugins_loaded priority 20 (after extrachill-multisite at priority 10)

### File Organization

```
extrachill-newsletter/
├── extrachill-newsletter.php          # Main plugin file (233 lines)
├── inc/
│   └── core/
│       ├── assets.php                  # Asset enqueuing (CSS/JS)
│       ├── sendy-api.php               # Sendy API integration and subscription function
│       ├── newsletter-post-type.php    # Custom newsletter post type (newsletter site only)
│       ├── newsletter-settings.php     # Admin settings page (newsletter site only)
│       ├── templates/
│       │   ├── generic-form.php        # Universal subscription form template
│       │   ├── email-template.php      # Email HTML template generation
│       │   └── recent-newsletters.php  # Newsletter list display
│       └── hooks/
│           ├── forms.php               # Form display hooks (archive, single post)
│           ├── homepage.php            # Homepage integration hooks
│           ├── post-meta.php           # Post meta display hooks
│           ├── sidebar.php             # Sidebar widget hooks
│           └── breadcrumbs.php         # Breadcrumb navigation hooks
├── assets/
│   ├── css/                            # Stylesheet files
│   └── js/                             # JavaScript files (form submission, etc.)
├── docs/
│   └── CHANGELOG.md                    # Version history
└── .buildignore                        # Build exclusion patterns
```

### Plugin File (extrachill-newsletter.php)

**Key Functions**:

- `is_newsletter_site()` - Check if current site is newsletter.extrachill.com (Blog ID 9)
- `extrachill_get_newsletter_context_presets()` - Returns preset configurations for form contexts (homepage, navigation, content, archive)
- `extrachill_render_newsletter_form( $context )` - Main action handler rendering newsletter forms with context-specific styling
- `get_newsletter_integrations()` - Retrieve all registered subscription integrations (filter-based)
- `newsletter_register_default_integrations()` - Register default integration contexts

**Integration Contexts Registered**:
- **navigation** - Newsletter form in site navigation menu
- **homepage** - Hero section subscription form on homepage
- **archive** - Newsletter archive page subscription
- **content** - Post-content newsletter signup (below post body)
- **contact** - Contact form newsletter subscription checkbox (integrated via `extrachill-contact`)

**Hooks Defined**:
- `extrachill_render_newsletter_form` - Action to render form for context
- `newsletter_form_integrations` - Filter to register/modify integrations
- `extrachill_newsletter_form_args` - Filter to customize form arguments per context
- `extrachill_navigation_before_social_links` - Action for navigation form display

## Sendy Integration

### Configuration Storage

**Network Options** (stored via `update_site_option()`):
```php
$settings = get_site_option( 'extrachill_newsletter_settings', array() );
```

**Stored Configuration Fields**:
- `sendy_api_key` - Sendy API key for authentication
- `sendy_url` - Sendy installation URL (default: `https://mail.extrachill.com/sendy`)
- `from_name` - Campaign sender name (default: "Extra Chill")
- `from_email` - Campaign from address (default: `newsletter@extrachill.com`)
- `reply_to` - Reply-to address (default: `chubes@extrachill.com`)
- `brand_id` - Sendy brand ID (default: "1")
- `{context}_list_id` - Sendy list ID per integration context (homepage_list_id, navigation_list_id, etc.)

### API Integration

**Function**: `get_sendy_config()` - Returns configuration array

**Configuration Structure**:
```php
array(
    'api_key'   => string (Sendy API key),
    'sendy_url' => string (Sendy base URL),
    'from_name' => string (Campaign sender),
    'from_email' => string (From address),
    'reply_to'  => string (Reply-to address),
    'brand_id'  => string (Sendy brand ID)
)
```

### Subscription Function

**Function**: `extrachill_multisite_subscribe( $email, $context )`

**Parameters**:
- `$email` - Email address to subscribe (validated via `is_email()`)
- `$context` - Integration context (homepage, navigation, content, archive, contact)

**Validation Steps**:
1. Verify integration context exists in registered integrations
2. Check list ID is configured for context
3. Validate email format
4. Verify Sendy API key is available

**Subscription Flow**:
1. Retrieve integration config and list ID from network options
2. Build subscription request with email, list ID, and API key
3. POST to Sendy subscribe endpoint: `{sendy_url}/subscribe`
4. Return status array with success flag and message

**Return Value**:
```php
array(
    'success' => bool,  // true if subscribed, false if error
    'message' => string // User-facing message
)
```

**Error Handling**:
- Logs integration errors to WordPress error log
- Returns user-friendly error messages (not technical details)
- Gracefully handles Sendy unavailability or network errors
- Validates email format before API call

**Sendy API Details**:
- **Endpoint**: POST `{sendy_url}/subscribe`
- **Content-Type**: application/x-www-form-urlencoded
- **Required Fields**: email, list, api_key
- **Optional Fields**: boolean (set to "true" for double opt-in)
- **Timeout**: 30 seconds

### List Management

**List ID Configuration**: Set via Settings page on newsletter.extrachill.com
- Each integration context requires its own Sendy list ID
- List IDs configured once, reused across all sites
- Empty list IDs disable subscription for that context
- Configuration accessible to all plugins via network options

**Cross-Site Subscriber Handling**:
- Single Sendy list per context receives subscribers from all network sites
- Subscriber email address is unique identifier (no user ID sent to Sendy)
- No subscriber tracking by site origin (all aggregated in Sendy)
- Optional: Use Sendy custom fields for site source tracking

## Subscription System

### Form Contexts

**Context Presets** (defined in `extrachill_get_newsletter_context_presets()`):

1. **homepage**
   - Wrapper class: `home-newsletter-signup newsletter-grid-section`
   - Heading: "Subscribe" (h3)
   - Description: "Stories and insights from the underground."
   - Layout: section (full-width)
   - Shows archive link: No
   - Use case: Homepage hero/featured section

2. **navigation**
   - Wrapper class: `menu-newsletter`
   - Heading: None
   - Layout: inline (minimal width)
   - Shows archive link: Yes
   - Use case: Site navigation/footer area

3. **content**
   - Wrapper class: `newsletter-content-section`
   - Heading: "Stay Connected with Extra Chill" (h3)
   - Description: "Get stories, reflections, and music industry insights delivered to your inbox."
   - Layout: section (full-width)
   - Shows archive link: Yes
   - Use case: After post content

4. **archive**
   - Wrapper class: `newsletter-subscription-form`
   - Heading: "Subscribe to Our Newsletter" (h2)
   - Description: "Get independent music journalism with personality delivered to your inbox."
   - Layout: section (full-width)
   - Shows archive link: No
   - Use case: Newsletter archive page (newsletter site only)

### Form Rendering

**Function**: `extrachill_render_newsletter_form( $context )`

**Rendering Process**:
1. Retrieve preset configuration for context
2. Apply `extrachill_newsletter_form_args` filter for customization
3. Enqueue form CSS/JavaScript via `wp_enqueue_style()` / `wp_enqueue_script()`
4. Include generic form template with preset arguments

**Template**: `inc/core/templates/forms/generic-form.php`

**Form Fields**:
- Email input (required, validated on submit)
- Subscribe button (handled by `assets/js/newsletter.js` via REST: `POST /wp-json/extrachill/v1/newsletter/subscribe`)
- Optional: Archive link (if `show_archive_link` = true)

**REST Submission**:
- Endpoint: `POST /wp-json/extrachill/v1/newsletter/subscribe`
- Header: `X-WP-Nonce: newsletterParams.restNonce`
- JSON body: `{ "email": "user@example.com", "context": "homepage" }`
- Delegates to `extrachill_multisite_subscribe()`
- Returns JSON `{ "message": "..." }` on success or `WP_Error` on failure

### Cross-Site Form Display

**Navigation Form** (All Sites):
- Displayed via hook: `extrachill_navigation_before_social_links`
- Rendered directly by newsletter plugin
- Uses 'navigation' context preset

**Other Contexts** (Per-Site):
- Forms triggered by theme/plugin hooks
- Theme determines when to display form
- Newsletter plugin provides rendering function via action hook

**Hook Pattern**:
```php
// Theme calls this hook when ready to display form
do_action( 'extrachill_render_newsletter_form', 'context_name' );

// Newsletter plugin handles the action
add_action( 'extrachill_render_newsletter_form', 'extrachill_render_newsletter_form' );
```

## Hooks & Filters System

### Core Filters

**`newsletter_form_integrations`** - Register custom integration contexts
- **Type**: Filter
- **Default Integrations**: navigation, homepage, archive, content, contact
- **Parameters**: `$integrations` (array keyed by context slug)
- **Return**: Array of integration definitions
- **Structure Per Integration**:
  ```php
  array(
      'label' => string,                    // Human-readable name
      'description' => string,              // Help text
      'list_id_key' => string               // Settings option key for Sendy list ID
  )
  ```
- **Example**:
  ```php
  add_filter( 'newsletter_form_integrations', function( $integrations ) {
      $integrations['my_context'] = array(
          'label' => 'My Custom Form',
          'description' => 'Form for my custom context',
          'list_id_key' => 'my_context_list_id'
      );
      return $integrations;
  });
  ```

**`extrachill_newsletter_form_args`** - Customize form rendering per context
- **Type**: Filter
- **Parameters**: `$args` (preset array), `$context` (context slug)
- **Return**: Modified arguments array
- **Customizable Properties**: wrapper_class, heading, heading_level, description, layout, placeholder, button_text, show_archive_link
- **Example**:
  ```php
  add_filter( 'extrachill_newsletter_form_args', function( $args, $context ) {
      if ( $context === 'homepage' ) {
          $args['heading'] = 'Join Our Mailing List';
          $args['button_text'] = 'Sign Up';
      }
      return $args;
  }, 10, 2);
  ```

### Core Actions

**`extrachill_render_newsletter_form`** - Render form for specific context
- **Type**: Action
- **Parameters**: `$context` (string)
- **Handler**: `extrachill_render_newsletter_form()` function
- **Usage**: Called by theme/plugins when form display needed
- **Example**:
  ```php
  do_action( 'extrachill_render_newsletter_form', 'homepage' );
  ```

**`extrachill_navigation_before_social_links`** - Hook point for navigation additions
- **Type**: Action (called by theme)
- **Usage**: Newsletter plugin hooks here to add navigation form
- **Parameters**: None
- **Example**:
  ```php
  add_action( 'extrachill_navigation_before_social_links', function() {
      do_action( 'extrachill_render_newsletter_form', 'navigation' );
  });
  ```

**`extrachill_archive_below_description`** - After archive page description (newsletter hook)
- **Type**: Action
- **Usage**: Archive page form display
- **Handler**: `extrachill_newsletter_archive_form()`

**`extrachill_after_post_content`** - After single post content (newsletter hook)
- **Type**: Action
- **Usage**: Content form display
- **Handler**: `extrachill_newsletter_after_post_content()`

**`newsletter_homepage_hero`** - Homepage hero section display (newsletter site only)
- **Type**: Action
- **Usage**: Newsletter site homepage form
- **Handler**: `extrachill_newsletter_homepage_hero_form()`

## Recent Updates (v0.2.0)

**Email Template Overhaul**:
- Modernized HTML email templates
- Improved mobile responsiveness
- Updated branding and styling

**REST API Migration**:
- Subscription forms now use REST API exclusively
- Endpoint: `POST /wp-json/extrachill/v1/newsletter/subscribe`
- Campaign push via REST: `POST /wp-json/extrachill/v1/newsletter/campaign/push`

## Recent Updates (v0.2.6)

**Theme Integration Fix**:

- Added theme integration to register newsletter post type with single-post style system

- Single newsletter posts now display theme single-post styles correctly

- Function: newsletter_single_post_style_types() in extrachill-newsletter.php

- Filter: extrachill_single_post_style_post_types

## REST API Endpoints

The newsletter plugin's frontend UI calls REST endpoints that are registered in the network-activated `extrachill-api` plugin.

### Subscribe

**Endpoint**: `POST /wp-json/extrachill/v1/newsletter/subscribe`

**Permission**: Public (`permission_callback` is `__return_true`)

**Body (JSON)**:
```json
{
  "email": "user@example.com",
  "context": "homepage"
}
```

**Response (Success)**:
```json
{
  "message": "..."
}
```

### Push newsletter to Sendy (admin UI meta box)

**Endpoint**: `POST /wp-json/extrachill/v1/newsletter/campaign/push`

**Permission**: `current_user_can( 'edit_posts' )`

**Body (JSON)**:
```json
{
  "post_id": 123
}
```

**Response (Success)**:
```json
{
  "message": "Successfully pushed to Sendy!",
  "campaign_id": "..."
}
```

## Newsletter Custom Post Type

**Status**: Newsletter site only (newsletter.extrachill.com, Blog ID 9)

**Post Type Slug**: `newsletter`

**Features**:
- Custom admin interface
- Title, content, featured image
- Publish date tracking
- Author attribution
- Status workflow (draft, pending, published)

**URL Structure**: `/newsletter/{post-name}/`

**Post Meta**:
- Sendy campaign ID (if campaign created)
- Email template HTML (auto-generated from post content)
- Campaign status (draft, scheduled, sent)

**Newsletter Settings Page**:
- Located: Newsletter → Settings (admin menu on newsletter site)
- Access: Network administrators only
- Configuration: Sendy API details, list IDs per integration, email sender info

**Not Used For**:
- Campaign creation (campaigns created directly in Sendy UI)
- Subscriber management (handled by Sendy)
- Email sending (Sendy handles all email delivery)

## Database Structure

### Network Options Table

**Option Key**: `extrachill_newsletter_settings`

**Stored As**: Serialized PHP array

**Fields**:
```php
array(
    'sendy_api_key'      => 'api_key_here',
    'sendy_url'          => 'https://mail.extrachill.com/sendy',
    'from_name'          => 'Extra Chill',
    'from_email'         => 'newsletter@extrachill.com',
    'reply_to'           => 'chubes@extrachill.com',
    'brand_id'           => '1',
    'homepage_list_id'   => 'list_id_abc123',
    'navigation_list_id' => 'list_id_def456',
    'archive_list_id'    => 'list_id_ghi789',
    'content_list_id'    => 'list_id_jkl012',
    'contact_list_id'    => 'list_id_mno345'
)
```

**Access Pattern**:
```php
// Get all settings
$settings = get_site_option( 'extrachill_newsletter_settings', array() );

// Update settings
update_site_option( 'extrachill_newsletter_settings', $settings );
```

### No Custom Tables

The newsletter plugin does **not** maintain subscriber data in WordPress. All subscriber information stored in Sendy:
- Email addresses
- Subscription status
- Campaign history
- List assignments

## Network-Wide Integration

### Cross-Site Functionality

**Subscription Forms Available On**:
- All 11 network sites
- Forms access network options for configuration
- Email submissions routed to Sendy via central function

**Settings Management**:
- Centralized on newsletter.extrachill.com
- Accessible only to network administrators
- Applied to all sites automatically

**Blog Context Handling**:
- Navigation form displays on all sites (priority: direct call)
- Other contexts handled by per-site themes/plugins
- No site-specific subscriber lists (all to Sendy)

### Site-to-Newsletter Site Communication

**Subscription Flow**:
1. Any site user submits email via form
2. Form context identified (homepage, content, etc.)
3. `extrachill_multisite_subscribe()` called with email and context
4. Function queries network options for Sendy list ID
5. Subscription routed to appropriate Sendy list

**No Blog Switching Required**:
- Network options accessible from any site context
- No per-site data stored (simplifies multi-site)

## Integration Patterns

### Contact Form Integration

**Plugin**: extrachill-contact

**Pattern**:
- Contact form displays optional newsletter checkbox
- If checked, email submitted to newsletter system
- Uses 'contact' integration context
- Sendy list ID configured separately from other forms

**Hook Points**:
- Filter: `newsletter_form_integrations` (contact form registers itself)
- Action: Called via contact form submission handler

### Blog Integration

**Plugin**: extrachill-blog

**Pattern**:
- Blog homepage displays newsletter signup form
- Form uses 'homepage' context preset
- Below-post forms use 'content' context preset
- Theme calls `do_action( 'extrachill_render_newsletter_form', 'context' )`

### Artist Platform Integration

**Plugin**: extrachill-artist-platform

**Pattern**:
- Artist signup may include newsletter subscription
- Uses 'navigation' or custom context
- Artist profile pages may display newsletter form

### Community Integration

**Plugin**: extrachill-community

**Pattern**:
- Member registration may link to newsletter signup
- Community forum sidebar displays newsletter form

### Theme Integration

**Purpose**: Registers newsletter post type with theme's single post style system to ensure single-post.css is loaded for newsletter posts.

**File**: extrachill-newsletter.php

**Filter**: extrachill_single_post_style_post_types

**Function**: newsletter_single_post_style_types() - Adds 'newsletter' to the array of post types that get single-post styles.

**Why**: Single newsletter posts were not displaying theme single-post styles because the plugin didn't register with the theme's filter, unlike other plugins (e.g., news-wire).

## Asset Loading Strategy

### CSS/JavaScript

**Files**: Located in `/assets/` directory

**Loading Pattern**:
- Enqueued only on pages with subscription forms
- Enqueued from `inc/core/assets.php`
- Uses `filemtime()` for cache busting
- Frontend CSS: form styling and layout
- Frontend JS: form submission via AJAX, Turnstile integration

**Conditional Loading**: Forms trigger asset enqueue via action hook

## Development Workflow

### Adding Custom Integration

**Steps to add custom form context**:

1. **Register integration** via filter:
```php
add_filter( 'newsletter_form_integrations', function( $integrations ) {
    $integrations['custom_context'] = array(
        'label' => 'Custom Form',
        'description' => 'Description of form',
        'list_id_key' => 'custom_context_list_id'
    );
    return $integrations;
});
```

2. **Configure Sendy list ID** in Newsletter Settings page

3. **Display form** where needed:
```php
do_action( 'extrachill_render_newsletter_form', 'custom_context' );
```

4. **Customize form** via filter (if needed):
```php
add_filter( 'extrachill_newsletter_form_args', function( $args, $context ) {
    if ( $context === 'custom_context' ) {
        $args['heading'] = 'Custom Heading';
    }
    return $args;
}, 10, 2);
```

### Testing Subscriptions Locally

**Development Setup**:
1. Configure Sendy API key in network settings (test environment)
2. Create test Sendy lists for each integration
3. Configure list IDs in newsletter settings
4. Test form submission via AJAX
5. Verify subscriber appears in Sendy list

**Testing Without Sendy**:
- Mock `extrachill_multisite_subscribe()` for testing
- Verify form renders correctly
- Check AJAX submission and response handling

## Build & Deployment

### Production Build Process

**Build System**: Use `homeboy build extrachill-newsletter` for production builds

**Build Steps**:
1. Clean previous builds
2. Install production dependencies: `composer install --no-dev`
3. Copy essential files to a temporary `/build/extrachill-newsletter/` directory
4. Exclude: vendor/, docs/, tests/, .git/, composer.lock, CLAUDE.md
5. Validate plugin file exists and loads
6. Create `/build/extrachill-newsletter.zip` (final output)
7. Remove the temporary `/build/extrachill-newsletter/` directory
8. Restore development dependencies: `composer install`

**Production Deployment**:

Production deployments and remote operations run through **Homeboy** (`homeboy/` in this repo). After building `build/extrachill-newsletter.zip`, Homeboy handles upload/activation steps per environment.

**Credential Management**:
- Sendy API key stored in network options (database)
- Alternative: Store in wp-config.php if required
- Never commit credentials to repository
- Use environment variables in deployment if needed

## Security Considerations

### Input Validation

**Email Validation**:
- All email inputs validated via WordPress `is_email()`
- Sanitized via `sanitize_email()` before processing
- Database entries escaped via prepared statements

**Form Submission**:
- Nonce verification on all AJAX submissions
- Nonce action: based on form context
- Nonce expires after 12 hours (WordPress default)

### Turnstile Integration

**Cloudflare Turnstile**:
- Network-wide configuration (managed by extrachill-multisite)
- Site key: Client-side widget rendering
- Secret key: Server-side token verification
- Server-side verification before Sendy API call
- Prevents bot subscriptions

**Graceful Degradation**:
- Forms work without Turnstile if not configured
- Turnstile optional (not required)
- No failures if Turnstile service unavailable

### Data Protection

**Subscriber Privacy**:
- Email only data point sent to Sendy
- No user ID, IP address, or tracking data sent
- Subscriber data stored only in Sendy (not WordPress)
- Complies with privacy requirements (no WordPress DB)

**API Key Security**:
- Stored in network options (database protected)
- Never logged or exposed in error messages
- Sent only to Sendy API via HTTPS
- Sanitized before storage

## Forbidden Patterns

**Do NOT**:
- Store subscriber emails in WordPress database (use Sendy only)
- Hardcode Sendy credentials in code (use network options)
- Use unencrypted HTTP for Sendy API calls (use HTTPS)
- Fallback to empty lists if list ID not configured (fail gracefully with error message)
- Create multiple subscribe functions for different contexts (use filters)
- Send unnecessary user data to Sendy (email address only)

## Planning Standards

### Adding Features

**Process**:
1. Verify feature works with Sendy API
2. Check for cross-site implications
3. Propose hook names and filter parameters
4. Consider how feature affects form display
5. Test with multiple integration contexts
6. Document integration patterns for other plugins

### Code Review Checklist
- [ ] Uses network options for network-wide data
- [ ] Sanitizes all user input
- [ ] Verifies nonces on AJAX submissions
- [ ] Includes error logging for debugging
- [ ] Uses wp_remote_post() for API calls
- [ ] Gracefully handles Sendy API errors
- [ ] Updates apply via hooks, not direct plugin calls
- [ ] No hardcoded Sendy credentials

## Dependencies

### Required
- **WordPress**: 5.0+ (multisite network)
- **PHP**: 7.4+
- **Sendy**: Email marketing instance with API configured

### Optional
- **Cloudflare Turnstile**: Bot prevention (via extrachill-multisite)
- **extrachill-multisite**: Blog ID helpers (fallback if unavailable)

### Not Required
- **WooCommerce**: E-commerce platform (separate site)
- **bbPress**: Community forums (separate site)
- **Additional plugins**: Works standalone

## Related Documentation

**Component Documentation**:
- [api-reference.md](docs/api-reference.md) - REST API endpoint details
- [integrations.md](docs/integrations.md) - Integration system and context guide
- [extrachill-multisite CLAUDE.md](../extrachill-multisite/CLAUDE.md) - Blog ID management, Turnstile integration
- [extrachill-contact CLAUDE.md](../extrachill-contact/CLAUDE.md) - Contact form newsletter integration
- [Root CLAUDE.md](../../CLAUDE.md) - Platform-wide architectural patterns

**External Resources**:
- [Sendy API Documentation](https://sendy.co/api)
- [Cloudflare Turnstile Docs](https://developers.cloudflare.com/turnstile/)
- [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/)
- [WordPress Multisite Handbook](https://developer.wordpress.org/plugins/multisite/)
