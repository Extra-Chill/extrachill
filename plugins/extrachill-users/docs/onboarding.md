# Onboarding System

User onboarding system providing guided setup, welcome flows, and new user engagement features for the Extra Chill Platform.

## Onboarding Flow

### Onboarding Steps
```php
// Define onboarding steps
function ec_get_onboarding_steps() {
    return [
        'welcome' => [
            'title' => 'Welcome to Extra Chill!',
            'description' => 'Let\'s get your profile set up',
            'template' => 'welcome-step',
            'required' => true,
            'skippable' => false
        ],
        'profile_completion' => [
            'title' => 'Complete Your Profile',
            'description' => 'Add your display name and bio',
            'template' => 'profile-step',
            'required' => true,
            'skippable' => false
        ],
        'avatar_upload' => [
            'title' => 'Add Your Avatar',
            'description' => 'Upload a profile picture',
            'template' => 'avatar-step',
            'required' => false,
            'skippable' => true
        ],
        'interests' => [
            'title' => 'Your Interests',
            'description' => 'Tell us what you\'re into',
            'template' => 'interests-step',
            'required' => false,
            'skippable' => true
        ],
        'community_intro' => [
            'title' => 'Join the Community',
            'description' => 'Learn about our community features',
            'template' => 'community-step',
            'required' => true,
            'skippable' => false
        ],
        'complete' => [
            'title' => 'You\'re All Set!',
            'description' => 'Start exploring Extra Chill',
            'template' => 'complete-step',
            'required' => true,
            'skippable' => false
        ]
    ];
}
```

### Onboarding State Management
```php
// Get user's onboarding progress
function ec_get_onboarding_progress($user_id) {
    $progress = get_user_meta($user_id, 'onboarding_progress', true) ?: [];
    $current_step = get_user_meta($user_id, 'onboarding_current_step', true) ?: 'welcome';
    $completed = get_user_meta($user_id, 'onboarding_completed', true) ?: false;
    $started_at = get_user_meta($user_id, 'onboarding_started', true);
    
    return [
        'current_step' => $current_step,
        'completed_steps' => $progress,
        'completed' => $completed,
        'started_at' => $started_at,
        'total_steps' => count(ec_get_onboarding_steps()),
        'completion_percentage' => ec_calculate_onboarding_percentage($progress)
    ];
}
```

## Onboarding Block

### Block Registration
```php
// Register onboarding Gutenberg block
function ec_register_onboarding_block() {
    register_block_type('extrachill/onboarding', [
        'editor_script' => 'extrachill-onboarding-block',
        'editor_style' => 'extrachill-onboarding-block',
        'style' => 'extrachill-onboarding',
        'render_callback' => 'ec_render_onboarding_block',
        'attributes' => [
            'showToLoggedInOnly' => [
                'type' => 'boolean',
                'default' => true
            ],
            'allowSkip' => [
                'type' => 'boolean',
                'default' => true
            ]
        ]
    ]);
}
```

### Block Rendering
```php
// Render onboarding block
function ec_render_onboarding_block($attributes) {
    $user_id = get_current_user_id();
    
    if (!$user_id && $attributes['showToLoggedInOnly']) {
        return '';
    }
    
    if (!$user_id) {
        return ec_render_login_prompt();
    }
    
    $progress = ec_get_onboarding_progress($user_id);
    
    if ($progress['completed']) {
        return ec_render_onboarding_complete($user_id);
    }
    
    return ec_render_onboarding_flow($user_id, $progress, $attributes);
}
```

### Step Rendering
```php
// Render individual onboarding step
function ec_render_onboarding_step($user_id, $step_key, $step_data, $progress) {
    $template_path = plugin_dir_path(__FILE__) . 'templates/onboarding/' . $step_data['template'] . '.php';
    
    ob_start();
    
    // Step header
    echo '<div class="onboarding-step" data-step="' . esc_attr($step_key) . '">';
    echo '<div class="step-header">';
    echo '<h2>' . esc_html($step_data['title']) . '</h2>';
    echo '<p>' . esc_html($step_data['description']) . '</p>';
    echo '</div>';
    
    // Step content
    echo '<div class="step-content">';
    
    if (file_exists($template_path)) {
        include $template_path;
    } else {
        ec_render_default_step_content($step_key, $step_data);
    }
    
    echo '</div>';
    
    // Step navigation
    echo '<div class="step-navigation">';
    ec_render_step_navigation($step_key, $step_data, $progress);
    echo '</div>';
    
    echo '</div>';
    
    return ob_get_clean();
}
```

## Profile Completion Step

### Profile Form
```php
// Render profile completion form
function ec_render_profile_completion_step($user_id) {
    $user = get_userdata($user_id);
    
    echo '<form class="onboarding-profile-form" method="post">';
    wp_nonce_field('onboarding_profile_' . $user_id, 'onboarding_nonce');
    
    echo '<div class="form-group">';
    echo '<label for="display_name">Display Name</label>';
    echo '<input type="text" id="display_name" name="display_name" value="' . esc_attr($user->display_name) . '" required />';
    echo '</div>';
    
    echo '<div class="form-group">';
    echo '<label for="user_bio">Bio</label>';
    echo '<textarea id="user_bio" name="user_bio" rows="4" placeholder="Tell us about yourself...">' . esc_textarea(get_the_author_meta('description', $user_id)) . '</textarea>';
    echo '</div>';
    
    echo '<div class="form-group">';
    echo '<label for="user_url">Website</label>';
    echo '<input type="url" id="user_url" name="user_url" value="' . esc_url($user->user_url) . '" placeholder="https://yourwebsite.com" />';
    echo '</div>';
    
    echo '<div class="form-group">';
    echo '<label for="user_location">Location</label>';
    echo '<input type="text" id="user_location" name="user_location" value="' . esc_attr(get_user_meta($user_id, 'location', true)) . '" placeholder="City, Country" />';
    echo '</div>';
    
    echo '<button type="submit" class="button button-primary">Continue</button>';
    
    echo '</form>';
}
```

### Profile Processing
```php
// Handle profile completion submission
function ec_handle_profile_completion($user_id, $data) {
    // Verify nonce
    if (!wp_verify_nonce($data['onboarding_nonce'], 'onboarding_profile_' . $user_id)) {
        return new WP_Error('invalid_nonce', 'Security check failed');
    }
    
    // Update user data
    $update_data = [
        'ID' => $user_id,
        'display_name' => sanitize_text_field($data['display_name']),
        'user_url' => esc_url_raw($data['user_url'])
    ];
    
    $result = wp_update_user($update_data);
    
    if (is_wp_error($result)) {
        return $result;
    }
    
    // Update user meta
    if (!empty($data['user_bio'])) {
        update_user_meta($user_id, 'description', sanitize_textarea_field($data['user_bio']));
    }
    
    if (!empty($data['user_location'])) {
        update_user_meta($user_id, 'location', sanitize_text_field($data['user_location']));
    }
    
    // Mark step as complete
    ec_mark_onboarding_step_complete($user_id, 'profile_completion');
    
    // Award points for profile completion
    ec_award_points($user_id, 'profile_completed');
    
    return true;
}
```

## Avatar Upload Step

### Avatar Upload Form
```php
// Render avatar upload step
function ec_render_avatar_upload_step($user_id) {
    echo '<div class="avatar-upload-container">';
    
    // Current avatar
    $current_avatar = get_avatar($user_id, 120);
    echo '<div class="current-avatar">' . $current_avatar . '</div>';
    
    // Upload form
    echo '<form class="avatar-upload-form" method="post" enctype="multipart/form-data">';
    wp_nonce_field('onboarding_avatar_' . $user_id, 'avatar_nonce');
    
    echo '<div class="upload-area" id="avatar-drop-zone">';
    echo '<input type="file" id="avatar-file" name="avatar_file" accept="image/*" />';
    echo '<label for="avatar-file" class="upload-label">';
    echo '<span class="upload-icon">' . ec_get_icon_svg('upload') . '</span>';
    echo '<span class="upload-text">Click or drag to upload avatar</span>';
    echo '</label>';
    echo '</div>';
    
    echo '<div class="avatar-preview" id="avatar-preview"></div>';
    
    echo '<div class="button-group">';
    echo '<button type="submit" class="button button-primary" id="save-avatar">Save Avatar</button>';
    echo '<button type="button" class="button" id="skip-avatar">Skip This Step</button>';
    echo '</div>';
    
    echo '</form>';
    echo '</div>';
}
```

### Avatar Processing
```php
// Handle avatar upload
function ec_handle_avatar_upload($user_id, $file) {
    // Verify nonce
    if (!wp_verify_nonce($_POST['avatar_nonce'], 'onboarding_avatar_' . $user_id)) {
        return new WP_Error('invalid_nonce', 'Security check failed');
    }
    
    // Validate file
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_error', 'File upload failed');
    }
    
    // Check file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    
    if (!in_array($file_type['type'], $allowed_types)) {
        return new WP_Error('invalid_file_type', 'Invalid file type. Please upload an image.');
    }
    
    // Upload to media library
    $upload = wp_handle_upload($file, ['test_form' => false]);
    
    if (isset($upload['error'])) {
        return new WP_Error('upload_failed', $upload['error']);
    }
    
    // Create attachment
    $attachment_id = wp_insert_attachment([
        'post_mime_type' => $upload['type'],
        'post_title' => 'Avatar for user ' . $user_id,
        'post_content' => '',
        'post_status' => 'inherit'
    ], $upload['file'], 0);
    
    // Generate thumbnails
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
    wp_update_attachment_metadata($attachment_id, $attachment_data);
    
    // Set as user avatar
    update_user_meta($user_id, 'avatar_url', wp_get_attachment_url($attachment_id));
    
    // Mark step as complete
    ec_mark_onboarding_step_complete($user_id, 'avatar_upload');
    
    // Award points for avatar upload
    ec_award_points($user_id, 'avatar_uploaded');
    
    return true;
}
```

## Interests Selection

### Interest Categories
```php
// Get interest categories for selection
function ec_get_interest_categories() {
    return [
        'music_genres' => [
            'label' => 'Music Genres',
            'options' => [
                'indie' => 'Indie Rock',
                'electronic' => 'Electronic',
                'hip_hop' => 'Hip Hop',
                'folk' => 'Folk',
                'punk' => 'Punk',
                'jazz' => 'Jazz',
                'experimental' => 'Experimental'
            ]
        ],
        'activities' => [
            'label' => 'Activities',
            'options' => [
                'live_shows' => 'Attending Live Shows',
                'discovering_music' => 'Discovering New Music',
                'playing_music' => 'Playing Music',
                'collecting_vinyl' => 'Collecting Vinyl',
                'music_production' => 'Music Production',
                'writing_reviews' => 'Writing Reviews'
            ]
        ],
        'content' => [
            'label' => 'Content Preferences',
            'options' => [
                'album_reviews' => 'Album Reviews',
                'artist_interviews' => 'Artist Interviews',
                'festival_coverage' => 'Festival Coverage',
                'music_news' => 'Music News',
                'playlists' => 'Curated Playlists',
                'discovery_guides' => 'Discovery Guides'
            ]
        ]
    ];
}
```

### Interests Form
```php
// Render interests selection step
function ec_render_interests_step($user_id) {
    $categories = ec_get_interest_categories();
    $user_interests = get_user_meta($user_id, 'user_interests', true) ?: [];
    
    echo '<form class="interests-form" method="post">';
    wp_nonce_field('onboarding_interests_' . $user_id, 'interests_nonce');
    
    foreach ($categories as $category_key => $category) {
        echo '<div class="interest-category">';
        echo '<h3>' . esc_html($category['label']) . '</h3>';
        echo '<div class="interest-options">';
        
        foreach ($category['options'] as $option_key => $option_label) {
            $checked = in_array($option_key, $user_interests) ? 'checked' : '';
            
            echo '<label class="interest-option">';
            echo '<input type="checkbox" name="interests[]" value="' . esc_attr($option_key) . '" ' . $checked . ' />';
            echo '<span class="checkbox-label">' . esc_html($option_label) . '</span>';
            echo '</label>';
        }
        
        echo '</div>';
        echo '</div>';
    }
    
    echo '<div class="button-group">';
    echo '<button type="submit" class="button button-primary">Continue</button>';
    echo '<button type="button" class="button" id="skip-interests">Skip This Step</button>';
    echo '</div>';
    
    echo '</form>';
}
```

## Onboarding Progress Tracking

### Step Completion
```php
// Mark onboarding step as complete
function ec_mark_onboarding_step_complete($user_id, $step_key) {
    $progress = get_user_meta($user_id, 'onboarding_progress', true) ?: [];
    
    if (!in_array($step_key, $progress)) {
        $progress[] = $step_key;
        update_user_meta($user_id, 'onboarding_progress', $progress);
    }
    
    // Move to next step
    $steps = array_keys(ec_get_onboarding_steps());
    $current_index = array_search($step_key, $steps);
    
    if ($current_index !== false && $current_index < count($steps) - 1) {
        $next_step = $steps[$current_index + 1];
        update_user_meta($user_id, 'onboarding_current_step', $next_step);
    } else {
        // Onboarding complete
        ec_complete_onboarding($user_id);
    }
}
```

### Onboarding Completion
```php
// Complete onboarding process
function ec_complete_onboarding($user_id) {
    update_user_meta($user_id, 'onboarding_completed', true);
    delete_user_meta($user_id, 'onboarding_current_step');
    
    // Award completion bonus
    ec_award_points($user_id, 'onboarding_completed');
    
    // Check for onboarding badges
    ec_check_and_award_badges($user_id);
    
    // Send welcome email
    ec_send_welcome_email($user_id);
    
    // Trigger completion action
    do_action('ec_onboarding_completed', $user_id);
}
```

## Integration Points

### Community Integration
```php
// Community onboarding integration
function ec_community_onboarding_integration($user_id) {
    // Auto-join community forums
    if (function_exists('bbp_add_user_to_forum')) {
        $main_forum_id = get_option('_bbp_root_forum_group_id');
        bbp_add_user_to_forum($user_id, $main_forum_id);
    }
    
    // Create welcome topic
    ec_create_welcome_topic($user_id);
}
```

### Newsletter Integration
```php
// Newsletter onboarding integration
function ec_newsletter_onboarding_integration($user_id, $interests) {
    if (in_array('music_news', $interests)) {
        // Subscribe to newsletter
        if (function_exists('extrachill_subscribe_user')) {
            $user = get_userdata($user_id);
            extrachill_subscribe_user($user->user_email, 'onboarding');
        }
    }
}
```

## API Integration

### Onboarding API
```php
// Register onboarding API endpoints
function ec_register_onboarding_api_endpoints() {
    register_rest_route('extrachill/v1', '/onboarding/progress', [
        'methods' => 'GET',
        'callback' => 'ec_api_get_onboarding_progress',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
    
    register_rest_route('extrachill/v1', '/onboarding/step', [
        'methods' => 'POST',
        'callback' => 'ec_api_complete_onboarding_step',
        'permission_callback' => function() {
            return is_user_logged_in();
        }
    ]);
}
```

## Security and Validation

### Input Validation
```php
// Validate onboarding submission
function ec_validate_onboarding_submission($step, $data) {
    switch ($step) {
        case 'profile_completion':
            return ec_validate_profile_data($data);
            
        case 'avatar_upload':
            return ec_validate_avatar_upload($data);
            
        case 'interests':
            return ec_validate_interests_selection($data);
            
        default:
            return true;
    }
}
```

## Performance Optimization

### Lazy Loading
```php
// Load onboarding resources only when needed
function ec_enqueue_onboarding_resources() {
    if (is_page('onboarding') || ec_should_show_onboarding()) {
        wp_enqueue_style('extrachill-onboarding');
        wp_enqueue_script('extrachill-onboarding');
    }
}
```