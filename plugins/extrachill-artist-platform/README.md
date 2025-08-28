# ExtraChill Artist Platform

A comprehensive WordPress plugin that provides artist profile management, link pages, and community features for musicians on the ExtraChill platform.

## Features

### 🎵 Artist Profiles
- Custom post type for artist/band profiles
- Forum integration with bbPress
- Roster management with invitation system
- Artist directory and following functionality
- Profile manager assignment and permissions

### 🔗 Link Pages
- Custom link page creation and management
- Live preview interface with drag-and-drop functionality
- Custom fonts, colors, and styling options
- YouTube video embed support with toggle control
- QR code generation and sharing modal
- Featured link highlighting system
- Social platform integration
- Click analytics and reporting

### 📊 Analytics Dashboard
- Track link clicks and user engagement
- Visual charts and reporting
- Export capabilities for data analysis

### 👥 Subscription Management
- Fan email collection system
- Artist-specific subscriber lists
- Integration with email marketing workflows

### 🔐 Cross-Domain Authentication
- Seamless login across ExtraChill subdomains
- Secure session token management
- 6-month token expiration with auto-cleanup

## Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher  
- **Theme**: Extra Chill Community theme
- **Optional**: bbPress (for forum features)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Ensure the Extra Chill Community theme is active
4. Configure plugin settings as needed

## Usage

### Creating Artist Profiles

1. Navigate to **Artist Profiles** in the WordPress admin
2. Click **Add New** to create a new artist profile
3. Fill in artist information and upload images
4. Configure forum settings and roster members
5. Set up profile managers and permissions
6. Publish the profile

### Managing Band Rosters

1. Access the artist profile management interface
2. Navigate to the **Profile Managers** tab
3. Send email invitations to band members
4. Track pending invitations and confirmations
5. Assign roles and permissions to roster members

### Managing Link Pages

1. Visit the artist profile management page
2. Navigate to the **Link Page** tab
3. Add links, customize appearance, and configure settings
4. Use the live preview to see changes in real-time
5. Save your changes

### Viewing Analytics

1. Access the artist profile management interface
2. Click on the **Analytics** tab
3. View click data, popular links, and engagement metrics
4. Use date filters to analyze specific time periods

## Development

### Core Classes

```php
// Main plugin initialization with theme compatibility check
ExtraChillArtistPlatform::instance();

// Access core functionality
$core = ExtraChillArtistPlatform_Core::instance();

// Template handling
$templates = ExtraChillArtistPlatform_Templates::instance();

// Asset management
$assets = ExtraChillArtistPlatform_Assets::instance();
```

### Adding Custom Features

The plugin uses WordPress hooks and filters extensively:

```php
// Modify link page data
add_filter('extrachill_link_page_data', function($data, $link_page_id) {
    // Your custom modifications
    return $data;
}, 10, 2);

// Hook into analytics tracking
add_action('extrachill_link_clicked', function($link_url, $link_page_id) {
    // Custom tracking logic
}, 10, 2);
```

### JavaScript Development

The management interface uses a modular JavaScript architecture with dual asset locations:

```javascript
// Access the main manager (loaded in manage-link-page-core.js)
if (window.ExtrchLinkPageManager) {
    ExtrchLinkPageManager.addCustomFeature(myCustomFeature);
}

// Listen for preview events
$(document).on('extrch:preview:updated', function(e, data) {
    // Handle preview updates
});

// Social link management
$(document).on('extrch:social:added', function(e, socialData) {
    // Handle new social links
});

// QR code generation events
$(document).on('extrch:qr:generated', function(e, qrData) {
    // Handle QR code creation
});
```

### Database Structure

The plugin creates several custom tables:

- `wp_user_session_tokens` - Cross-domain authentication (integrated in Core class)
- `wp_link_page_analytics` - Click tracking with referrer data and timestamps
- `wp_artist_subscribers` - Artist subscription data with export status tracking

### Roster Data Storage

Band roster data is stored using WordPress post meta:
- `_pending_invitations` - Array of pending roster invitations with tokens
- `_roster_members` - Confirmed band member data with roles

## Customization

### Styling

Override plugin styles in your theme:

```css
/* Customize link page appearance */
.extrch-link-page {
    /* Your custom styles */
}

/* Modify management interface */
.extrch-manage-tabs {
    /* Your admin styles */
}
```

### Hooks & Filters

```php
// Modify supported social link types
add_filter('bp_supported_social_link_types', function($types) {
    $types['custom_platform'] = [
        'label' => 'Custom Platform',
        'icon' => 'fa-custom',
        'color' => '#ff0000'
    ];
    return $types;
});

// Customize roster invitation emails
add_filter('bp_roster_invitation_email_subject', function($subject, $artist_name) {
    return "Join {$artist_name} on ExtraChill";
}, 10, 2);

// Modify artist following functionality
add_action('bp_artist_followed', function($user_id, $artist_id) {
    // Custom follow actions
}, 10, 2);

// Customize subscription email templates
add_filter('extrachill_subscription_email_template', function($template, $data) {
    return $custom_template;
}, 10, 2);
```

## Troubleshooting

### Theme Compatibility Issues
Ensure the Extra Chill Community theme is active. The plugin will display an admin notice if an incompatible theme is detected.

### Link Page Not Loading
Check that rewrite rules are flushed by deactivating and reactivating the plugin.

### Analytics Not Tracking
Verify that JavaScript is not blocked and check browser console for errors.

### Session Issues
Clear cookies for the `.extrachill.com` domain and try logging in again.

### Roster Invitations Not Sending
Check that WordPress can send emails and verify SMTP configuration. Review invitation tokens in database if needed.

### Duplicate JavaScript Assets
The plugin maintains JavaScript files in both `assets/js/` and `artist-platform/extrch.co-link-page/` directories for compatibility. This is intentional.

## Support

For issues and feature requests, contact the development team or submit issues through the project repository.

## License

GPL v2 or later - see LICENSE file for details.