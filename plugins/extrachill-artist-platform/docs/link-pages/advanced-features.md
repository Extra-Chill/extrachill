# Advanced Link Page Features

Comprehensive advanced functionality for link pages including expiration, redirects, analytics tracking, and YouTube integration.

## Link Expiration System

### Time-Based Link Management

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js`

Features:
- Schedule link activation/deactivation
- Automatic expiration handling
- Bulk expiration management
- Expiration notifications via Gutenberg block editor interface

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

Configuration via Gutenberg block editor for 302 redirects to external URLs

## YouTube Integration

### Inline Video Embedding

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js`

Configuration via Gutenberg block editor to enable YouTube video embedding with automatic detection

## Analytics Tracking

Analytics integration configured via Gutenberg block editor with support for:

## Subscription Settings

### Email Collection Configuration

Location: `src/blocks/link-page-editor/components/tabs/TabAdvanced.js`

Configuration via Gutenberg block editor for:
- Subscription display modes (icon modal, inline form, button modal, disabled)
- Custom subscription messaging
- Button text customization

## QR Code Generation

Legacy manage-link-page QR code AJAX and JS have been removed with the PHP management interface. QR handling is expected via the block/REST flow.


### QR Code Interface

Location: `inc/link-pages/management/assets/js/qrcode.js`

```javascript
const QRCodeManager = {
    init: function() {
        this.bindEvents();
    },
    
    bindEvents: function() {
        $('#generate-qr-code').on('click', this.generateQRCode.bind(this));
        $('#download-qr-code').on('click', this.downloadQRCode.bind(this));
    },
    
    generateQRCode: function() {
        const linkPageId = $('#link-page-id').val();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'generate_qr_code',
                link_page_id: linkPageId,
                nonce: qr_nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#qr-code-display').html(
                        `<img src="${response.data.qr_code_url}" alt="QR Code">`
                    );
                    $('#download-qr-code').attr('href', response.data.download_url);
                }
            }
        });
    }
};
```

## QR Code Generation

QR code generation is handled via REST API in the Gutenberg block editor with the `QRCodeModal` component.

Location: `src/blocks/link-page-editor/components/shared/QRCodeModal.js`

Features:
- High-resolution QR code output (1000px)
- REST API-powered generation
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