# Subscription System

Comprehensive email subscription management system allowing artists to collect and manage subscriber lists with multiple collection methods and export capabilities.

## Database Schema

### Subscriber Table

Location: `inc/database/subscriber-db.php`

```sql
CREATE TABLE wp_artist_subscribers (
    subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT(20) UNSIGNED NULL,
    artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
    subscriber_email VARCHAR(255) NOT NULL,
    username VARCHAR(60) NULL DEFAULT NULL,
    source VARCHAR(50) NOT NULL DEFAULT 'platform_follow_consent',
    subscribed_at DATETIME NOT NULL,
    exported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (subscriber_id),
    UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
    KEY artist_profile_id (artist_profile_id),
    KEY exported (exported),
    KEY user_id (user_id),
    KEY user_artist_source (user_id, artist_profile_id, source)
);
```

### Table Creation

```php
function extrch_create_subscribers_table() {
    global $wpdb;
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NULL,
        artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
        subscriber_email VARCHAR(255) NOT NULL,
        username VARCHAR(60) NULL DEFAULT NULL,
        source VARCHAR(50) NOT NULL DEFAULT 'platform_follow_consent',
        subscribed_at DATETIME NOT NULL,
        exported TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (subscriber_id),
        UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
        KEY artist_profile_id (artist_profile_id),
        KEY exported (exported),
        KEY user_id (user_id),
        KEY user_artist_source (user_id, artist_profile_id, source)
    ) $charset_collate;";
    
    dbDelta($sql);
}
```

## Subscription Collection Methods

### Inline Form

Location: `inc/link-pages/live/templates/subscribe-inline-form.php`

Embedded form within link page content:

```php
<div class="subscribe-inline-form">
    <h3><?php echo esc_html($subscribe_description); ?></h3>
    <form id="inline-subscribe-form" class="subscribe-form">
        <input type="email" 
               name="subscriber_email" 
               placeholder="Enter your email" 
               required>
        <button type="submit"><?php echo esc_html($subscribe_button_text); ?></button>
        <input type="hidden" name="artist_id" value="<?php echo esc_attr($artist_id); ?>">
        <input type="hidden" name="source" value="inline_form">
    </form>
    <div class="subscribe-message"></div>
</div>
```

### Modal Form

Location: `inc/link-pages/live/templates/subscribe-modal.php`

Modal popup triggered by button or icon:

```php
<div id="subscribe-modal" class="subscribe-modal" style="display: none;">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h3>Subscribe to <?php echo esc_html($artist_name); ?></h3>
        <p><?php echo esc_html($subscribe_description); ?></p>
        
        <form id="modal-subscribe-form" class="subscribe-form">
            <input type="email" 
                   name="subscriber_email" 
                   placeholder="Enter your email" 
                   required>
            <button type="submit"><?php echo esc_html($subscribe_button_text); ?></button>
            <input type="hidden" name="artist_id" value="<?php echo esc_attr($artist_id); ?>">
            <input type="hidden" name="source" value="modal_form">
        </form>
        
        <div class="subscribe-message"></div>
    </div>
</div>
```

### Icon Trigger

Subscription icon that opens modal:

```php
<?php if ($subscribe_display_mode === 'icon_modal'): ?>
    <a href="#" class="subscribe-trigger-icon" data-artist-id="<?php echo esc_attr($artist_id); ?>">
        <i class="fas fa-envelope"></i>
        <span>Subscribe</span>
    </a>
<?php endif; ?>
```

## JavaScript Integration

### Client-Side Handling

Location: `inc/link-pages/live/assets/js/link-page-subscribe.js`

```javascript
const SubscriptionManager = {
    init: function() {
        this.bindEvents();
    },

    bindEvents: function() {
        // Modal triggers
        document.addEventListener('click', (e) => {
            if (e.target.matches('.subscribe-trigger-icon')) {
                this.openModal(e);
            } else if (e.target.matches('.close-modal')) {
                this.closeModal(e);
            }
        });

        // Form submissions
        document.addEventListener('submit', (e) => {
            if (e.target.matches('.subscribe-form')) {
                this.handleSubscription(e);
            }
        });
    },

    openModal: function(e) {
        e.preventDefault();
        const modal = document.getElementById('subscribe-modal');
        if (modal) modal.style.display = 'block';
    },

    closeModal: function(e) {
        e.preventDefault();
        const modal = document.getElementById('subscribe-modal');
        if (modal) modal.style.display = 'none';
    },

    handleSubscription: function(e) {
        e.preventDefault();

        const form = e.target;
        const email = form.querySelector('input[name="subscriber_email"]').value;
        const artistId = form.querySelector('input[name="artist_id"]').value;
        const source = form.querySelector('input[name="source"]').value;

        fetch(subscribe_ajax.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'extrch_link_page_subscribe',
                subscriber_email: email,
                artist_id: artistId,
                source: source,
                nonce: subscribe_ajax.nonce
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                form.style.display = 'none';
                const messageEl = document.querySelector('.subscribe-message');
                if (messageEl) {
                    messageEl.innerHTML = '<p class="success">' + data.message + '</p>';
                }

                // Close modal after delay if modal form
                if (source === 'modal_form') {
                    setTimeout(() => {
                        const modal = document.getElementById('subscribe-modal');
                        if (modal) modal.style.display = 'none';
                    }, 2000);
                }
            } else {
                const messageEl = document.querySelector('.subscribe-message');
                if (messageEl) {
                    messageEl.innerHTML = '<p class="error">' + data.message + '</p>';
                }
            }
        });
    }
};

document.addEventListener('DOMContentLoaded', () => SubscriptionManager.init());
```

## Server-Side Processing

Legacy manage-link-page subscription AJAX has been removed with the PHP management interface. Subscription handling remains via core subscriber data functions and block/REST flows.

### Data Functions

Location: `inc/artist-profiles/subscribe-data-functions.php`

```php
/**
 * Add subscriber to database
 */
function add_artist_subscriber($email, $artist_id, $source = 'platform_follow_consent') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    
    // Check for existing subscription
    $existing = $wpdb->get_var($wpdb->prepare("
        SELECT subscriber_id FROM {$table_name} 
        WHERE subscriber_email = %s AND artist_profile_id = %d
    ", $email, $artist_id));
    
    if ($existing) {
        return ['success' => false, 'message' => 'You are already subscribed'];
    }
    
    // Get user ID if registered user
    $user = get_user_by('email', $email);
    $user_id = $user ? $user->ID : null;
    $username = $user ? $user->display_name : null;
    
    // Insert subscriber
    $result = $wpdb->insert(
        $table_name,
        [
            'user_id' => $user_id,
            'artist_profile_id' => $artist_id,
            'subscriber_email' => $email,
            'username' => $username,
            'source' => $source,
            'subscribed_at' => current_time('mysql'),
            'exported' => 0
        ],
        ['%d', '%d', '%s', '%s', '%s', '%s', '%d']
    );
    
    if ($result === false) {
        return ['success' => false, 'message' => 'Failed to save subscription'];
    }
    
    // Trigger action for integrations
    do_action('extrch_subscriber_added', $wpdb->insert_id, $email, $artist_id, $source);
    
    return ['success' => true, 'message' => 'Subscription added successfully'];
}

/**
 * Get subscribers for artist
 */
function get_artist_subscribers($artist_id, $args = []) {
    global $wpdb;
    
    $defaults = [
        'per_page' => 20,
        'page' => 1,
        'include_exported' => false,
        'source' => null
    ];
    $args = wp_parse_args($args, $defaults);
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    $offset = ($args['page'] - 1) * $args['per_page'];
    
    $where_clause = $wpdb->prepare("WHERE artist_profile_id = %d", $artist_id);
    
    if (!$args['include_exported']) {
        $where_clause .= " AND (exported = 0 OR exported IS NULL)";
    }
    
    if ($args['source']) {
        $where_clause .= $wpdb->prepare(" AND source = %s", $args['source']);
    }
    
    $sql = $wpdb->prepare("
        SELECT * FROM {$table_name} 
        {$where_clause} 
        ORDER BY subscribed_at DESC 
        LIMIT %d OFFSET %d
    ", $args['per_page'], $offset);
    
    return $wpdb->get_results($sql);
}
```

## Management Interface

### Subscriber Management Tab

Location: `inc/artist-profiles/frontend/templates/manage-artist-profile-tabs/tab-subscribers.php`

Features:
- Subscriber list with pagination
- Export functionality  
- Bulk actions
- Source filtering

### JavaScript Management

Location: `inc/artist-profiles/assets/js/manage-artist-subscribers.js`

Subscriber management uses REST API with fetch API for modern data handling:

```javascript
// Fetch subscribers via REST API
const response = await fetch( `/wp-json/extrachill/v1/subscribers/${artistId}`, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
        'X-WP-Nonce': wpApiNonce
    }
});

const subscribers = await response.json();
```

Features:
- Subscriber list with pagination
- Export functionality via CSV download
- Bulk actions
- Source filtering

## Export Functionality

### CSV Export

```php
/**
 * Export subscribers to CSV
 */
function export_artist_subscribers_csv() {
    if (!wp_verify_nonce($_GET['nonce'], 'subscriber_export_nonce')) {
        wp_die('Security check failed');
    }
    
    $artist_id = (int) $_GET['artist_id'];
    
    // Check permissions
    if (!ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        wp_die('Insufficient permissions');
    }
    
    // Get all subscribers
    $subscribers = get_artist_subscribers($artist_id, ['per_page' => -1]);
    
    // Set CSV headers
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="subscribers-' . $artist_id . '-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write header row
    fputcsv($output, ['Email', 'Username', 'Source', 'Subscribed Date', 'User ID']);
    
    // Write data rows
    foreach ($subscribers as $subscriber) {
        fputcsv($output, [
            $subscriber->subscriber_email,
            $subscriber->username ?: 'N/A',
            $subscriber->source,
            $subscriber->subscribed_at,
            $subscriber->user_id ?: 'N/A'
        ]);
    }
    
    fclose($output);
    
    // Mark as exported
    mark_subscribers_as_exported($artist_id);
    
    exit;
}

/**
 * Mark subscribers as exported
 */
function mark_subscribers_as_exported($artist_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    
    $wpdb->update(
        $table_name,
        ['exported' => 1],
        ['artist_profile_id' => $artist_id, 'exported' => 0],
        ['%d'],
        ['%d', '%d']
    );
}
```

## Subscription Sources

### Source Types

The system tracks subscription sources:

- `inline_form`: Inline form on link page
- `modal_form`: Modal popup form  
- `icon_modal`: Icon-triggered modal
- `platform_follow_consent`: Following artist on platform
- `manual_import`: Manually imported subscribers

### Source-Based Filtering

```php
// Get subscribers by source
$modal_subscribers = get_artist_subscribers($artist_id, ['source' => 'modal_form']);
$inline_subscribers = get_artist_subscribers($artist_id, ['source' => 'inline_form']);
```

## Integration Points

### Email Service Integration

Subscription system supports integration with email services:

```php
/**
 * Add subscriber to external email service
 */
function sync_to_email_service($subscriber_id, $email, $artist_id, $source) {
    $artist_name = get_the_title($artist_id);
    
    // Example: Mailchimp integration
    if (function_exists('mailchimp_add_subscriber')) {
        mailchimp_add_subscriber($email, [
            'ARTIST' => $artist_name,
            'SOURCE' => $source
        ]);
    }
    
    // Example: ConvertKit integration
    if (function_exists('convertkit_add_subscriber')) {
        convertkit_add_subscriber($email, ['artist_id' => $artist_id]);
    }
}
add_action('extrch_subscriber_added', 'sync_to_email_service', 10, 4);
```

### WordPress User Integration

System links subscriptions to WordPress user accounts when available:

```php
// Auto-subscribe when user follows artist
function auto_subscribe_on_follow($user_id, $artist_id) {
    $user = get_userdata($user_id);
    if ($user) {
        add_artist_subscriber($user->user_email, $artist_id, 'platform_follow_consent');
    }
}
add_action('extrch_user_followed_artist', 'auto_subscribe_on_follow', 10, 2);
```

## Privacy Compliance

### Data Protection

```php
/**
 * Remove subscriber data (GDPR compliance)
 */
function remove_subscriber_data($email, $artist_id = null) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    
    $where = ['subscriber_email' => $email];
    $where_format = ['%s'];
    
    if ($artist_id) {
        $where['artist_profile_id'] = $artist_id;
        $where_format[] = '%d';
    }
    
    $result = $wpdb->delete($table_name, $where, $where_format);
    
    do_action('extrch_subscriber_data_removed', $email, $artist_id);
    
    return $result;
}
```

### Unsubscribe Functionality

```php
/**
 * Generate unsubscribe link
 */
function generate_unsubscribe_link($email, $artist_id) {
    $token = wp_hash($email . $artist_id . wp_salt());
    
    return add_query_arg([
        'action' => 'unsubscribe',
        'email' => urlencode($email),
        'artist' => $artist_id,
        'token' => $token
    ], home_url());
}
```