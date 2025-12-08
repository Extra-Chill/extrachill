# Advanced Link Page Features

Comprehensive advanced functionality for link pages including expiration, redirects, analytics tracking, and YouTube integration.

## Link Expiration System

### Time-Based Link Management

Location: `inc/link-pages/management/advanced-tab/link-expiration.php`

Features:
- Schedule link activation/deactivation
- Automatic expiration handling
- Bulk expiration management
- Expiration notifications

### Configuration Options

```php
// Expiration settings stored in link page meta
$expiration_settings = [
    '_link_expiration_enabled' => true,
    '_link_expiration_date' => '2024-12-31 23:59:59',
    '_link_expiration_action' => 'hide', // 'hide', 'redirect', 'message'
    '_link_expiration_message' => 'This link page has expired',
    '_link_expiration_redirect_url' => 'https://example.com/expired'
];
```

### JavaScript Interface

Location: `inc/link-pages/management/assets/js/link-expiration.js`

```javascript
const LinkExpirationManager = {
    init: function() {
        this.bindEvents();
        this.initDatePicker();
    },
    
    bindEvents: function() {
        $('#link-expiration-enabled').on('change', this.toggleExpirationSettings.bind(this));
        $('#expiration-date').on('change', this.updateExpirationPreview.bind(this));
    },
    
    toggleExpirationSettings: function(e) {
        const isEnabled = e.target.checked;
        $('.expiration-settings').toggle(isEnabled);
        
        // Update preview
        document.dispatchEvent(new CustomEvent('expirationChanged', {
            detail: { enabled: isEnabled }
        }));
    }
};
```

### Preview Integration

Location: `inc/link-pages/management/live-preview/assets/js/link-expiration-preview.js`

```javascript
// Update preview based on expiration settings
document.addEventListener('expirationChanged', function(e) {
    const previewFrame = document.getElementById('live-preview-iframe');
    const previewDoc = previewFrame.contentDocument;
    
    if (e.detail.enabled) {
        // Show expiration indicator in preview
        const indicator = previewDoc.createElement('div');
        indicator.className = 'expiration-indicator';
        indicator.textContent = 'Expires: ' + e.detail.date;
        previewDoc.body.appendChild(indicator);
    }
});
```

## Redirect System

### Temporary Redirects

Location: `inc/link-pages/management/advanced-tab/temporary-redirect.php`

Implements 302 redirects to external URLs:

```php
function handle_link_page_redirect($link_page_id) {
    $redirect_enabled = get_post_meta($link_page_id, '_link_page_redirect_enabled', true);
    $redirect_url = get_post_meta($link_page_id, '_link_page_redirect_target_url', true);
    
    if ($redirect_enabled && $redirect_url) {
        // Track redirect for analytics
        do_action('extrch_track_redirect', $link_page_id, $redirect_url);
        
        // Perform redirect
        wp_redirect($redirect_url, 302);
        exit;
    }
}
```

### Redirect Configuration

```php
// Redirect settings
$redirect_settings = [
    '_link_page_redirect_enabled' => true,
    '_link_page_redirect_target_url' => 'https://external-site.com',
    '_link_page_redirect_delay' => 0, // Seconds delay
    '_link_page_redirect_message' => 'Redirecting...'
];
```

## YouTube Integration

### Inline Video Embedding

Location: `inc/link-pages/management/advanced-tab/youtube-embed-control.php`

Automatic detection and embedding of YouTube links:

```php
function process_youtube_links($links) {
    foreach ($links as &$link) {
        if (is_youtube_url($link['link_url'])) {
            $video_id = extract_youtube_video_id($link['link_url']);
            $link['youtube_embed'] = [
                'video_id' => $video_id,
                'thumbnail' => "https://img.youtube.com/vi/{$video_id}/maxresdefault.jpg",
                'embed_url' => "https://www.youtube.com/embed/{$video_id}"
            ];
        }
    }
    return $links;
}

function is_youtube_url($url) {
    return preg_match('/(?:youtube\.com|youtu\.be)/', $url);
}

function extract_youtube_video_id($url) {
    preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches);
    return isset($matches[1]) ? $matches[1] : null;
}
```

### YouTube Player Integration

Location: `inc/link-pages/live/assets/js/link-page-youtube-embed.js`

```javascript
const YouTubeManager = {
    init: function() {
        this.processYouTubeLinks();
        this.bindEvents();
    },
    
    processYouTubeLinks: function() {
        const youtubeLinks = document.querySelectorAll('a[href*="youtube.com"], a[href*="youtu.be"]');
        
        youtubeLinks.forEach(link => {
            if (this.shouldEmbedVideo(link)) {
                this.createEmbedPlayer(link);
            }
        });
    },
    
    createEmbedPlayer: function(link) {
        const videoId = this.extractVideoId(link.href);
        const embedContainer = document.createElement('div');
        embedContainer.className = 'youtube-embed-container';
        embedContainer.innerHTML = `
            <iframe src="https://www.youtube.com/embed/${videoId}" 
                    frameborder="0" 
                    allowfullscreen></iframe>
        `;
        
        link.parentNode.insertBefore(embedContainer, link.nextSibling);
    }
};
```

## Analytics Tracking

### Meta Pixel Integration

Location: `inc/link-pages/management/advanced-tab/meta-pixel-tracking.php`

```php
function render_meta_pixel_code($pixel_id) {
    if (empty($pixel_id)) return;
    
    ?>
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '<?php echo esc_js($pixel_id); ?>');
    fbq('track', 'PageView');
    </script>
    <?php
}
```

### Google Tag Manager Integration

Location: `inc/link-pages/management/advanced-tab/google-tag-tracking.php`

```php
function render_gtm_code($gtm_id) {
    if (empty($gtm_id)) return;
    
    ?>
    <!-- Google Tag Manager -->
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
    new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
    j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
    'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
    })(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');</script>
    <?php
}
```

### Custom Analytics Events

```javascript
// Track link clicks with custom events
function trackLinkClick(linkUrl, linkText) {
    // Google Analytics 4
    if (typeof gtag !== 'undefined') {
        gtag('event', 'link_click', {
            'link_url': linkUrl,
            'link_text': linkText,
            'page_title': document.title
        });
    }
    
    // Meta Pixel
    if (typeof fbq !== 'undefined') {
        fbq('track', 'Lead', {
            'content_name': linkText,
            'content_category': 'Link Click'
        });
    }
}
```

## Subscription Settings

### Email Collection Configuration

Location: `inc/link-pages/management/advanced-tab/subscription-settings.php`

```php
// Subscription display modes
$subscription_modes = [
    'icon_modal' => 'Icon with Modal',
    'inline_form' => 'Inline Form',
    'button_modal' => 'Button with Modal',
    'disabled' => 'Disabled'
];

// Subscription settings
$subscription_settings = [
    '_link_page_subscribe_display_mode' => 'icon_modal',
    '_link_page_subscribe_description' => 'Join our mailing list',
    '_link_page_subscribe_button_text' => 'Subscribe',
    '_link_page_subscribe_success_message' => 'Thanks for subscribing!'
];
```

### Subscription Form Templates

Templates adapt based on display mode:

```php
// Modal subscription form
if ($subscribe_display_mode === 'icon_modal') {
    include 'templates/subscribe-modal.php';
}

// Inline subscription form  
if ($subscribe_display_mode === 'inline_form') {
    include 'templates/subscribe-inline-form.php';
}
```

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

## Weekly Email Notifications

### Automated Email Reports

Location: `inc/link-pages/management/advanced-tab/link-page-weekly-email.php`

```php
function send_weekly_link_page_report($artist_id) {
    $link_page_id = ec_get_link_page_for_artist($artist_id);
    if (!$link_page_id) return;
    
    // Get weekly analytics
    $analytics = get_link_page_weekly_analytics($link_page_id);
    
    // Get artist email
    $artist_email = get_artist_notification_email($artist_id);
    
    // Prepare email content
    $subject = sprintf('Weekly Report for %s', get_the_title($artist_id));
    $message = build_weekly_report_email($analytics);
    
    // Send email
    wp_mail($artist_email, $subject, $message, [
        'Content-Type: text/html; charset=UTF-8'
    ]);
}

// Schedule weekly emails
function schedule_weekly_reports() {
    if (!wp_next_scheduled('send_weekly_reports')) {
        wp_schedule_event(time(), 'weekly', 'send_weekly_reports');
    }
}
add_action('wp', 'schedule_weekly_reports');
```

## Advanced Settings Integration

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