# Advanced Link Page Features

Comprehensive advanced functionality for link pages including expiration, redirects, analytics tracking, and YouTube integration.

## Link Expiration System

### Time-Based Link Management

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js` (editor UI)

Features:
- Schedule link activation/deactivation
- Automatic expiration handling via cron job
- Bulk expiration management
- Expiration notifications via Gutenberg block editor interface

**Backend Implementation**: `inc/core/actions/link-expiration-cron.php`
- Scheduled WordPress cron job runs daily
- Automatically deactivates expired links based on configured dates
- Can be manually triggered via action hook

### Configuration Options

```php
// Expiration settings stored in link page meta
$expiration_settings = [
    'link_expiration_enabled' => true,
    'link_expiration_date' => '2024-12-31 23:59:59',
    'link_expiration_action' => 'hide' // 'hide', 'redirect', 'message'
];
```

## Redirect System

### Temporary Redirects

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js`

Configuration via Gutenberg block editor for 302 redirects to external URLs. When enabled, a redirect target URL sends visitors to an external site instead of displaying the link page.

## YouTube Integration

### Inline Video Embedding

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js` (editor UI)

Configuration via Gutenberg block editor to enable YouTube video embedding with automatic detection. Public rendering handled by `inc/link-pages/live/assets/js/link-page-youtube-embed.js`.

## Analytics Tracking

Analytics integration configured via Gutenberg block editor with support for:

- Google Tag Manager (GTM) tracking IDs
- Meta Pixel (Facebook) conversion tracking
- Custom event tracking via REST API endpoints
- Real-time click tracking via sendBeacon API
- Page view tracking with automatic daily aggregation

## Subscription Settings

### Email Collection Configuration

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js`

Configuration via Gutenberg block editor for:
- Subscription display modes (icon modal, inline form, button modal, disabled)
- Custom subscription messaging
- Button text customization
- Email collection via modal or inline forms

## QR Code Generation

QR code generation is handled via REST API in the Gutenberg block editor with the `QRCodeModal` component.

Location: `src/blocks/link-page-editor/components/shared/QRCodeModal.js`

Features:
- High-resolution QR code output (1000px)
- REST API-powered generation via extrachill-api plugin
- PNG download functionality
- Modal interface in Gutenberg editor

### Settings Data Structure

All advanced settings stored in link page metadata:

```php
$advanced_settings = [
    // Expiration
    'link_expiration_enabled' => false,
    'link_expiration_date' => '',
    'link_expiration_action' => 'hide',
    
    // Redirect
    'redirect_enabled' => false,
    'redirect_target_url' => '',
    
    // YouTube
    'youtube_embed_enabled' => true,
    
    // Analytics
    'meta_pixel_id' => '',
    'google_tag_id' => '',
    'google_tag_manager_id' => '',
    
    // Subscription
    'subscribe_display_mode' => 'icon_modal',
    'subscribe_description' => ''
];
```

### Settings Processing

Advanced settings processed through centralized data system:

```php
// Settings loaded via ec_get_link_page_data()
$data = ec_get_link_page_data($artist_id, $link_page_id);
$settings = $data['settings'];

// Access individual settings
$expiration_enabled = $settings['link_expiration_enabled'];
$redirect_url = $settings['redirect_target_url'];
$youtube_enabled = $settings['youtube_embed_enabled'];
```