<?php
/**
 * Forum Homepage Display Management
 * 
 * Admin functionality for controlling which forums display on the community homepage.
 * Adds checkbox to forum edit pages for homepage display control.
 * 
 * @package Extra ChillCommunity
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// WordPress meta box registration (working pattern)
function extrachill_add_homepage_display_meta_box() {
    add_meta_box(
        'extrachill_homepage_display',
        'Homepage Display',
        'extrachill_homepage_display_meta_box_callback',
        'forum',
        'side',
        'high'
    );
}
add_action('add_meta_boxes', 'extrachill_add_homepage_display_meta_box');

// Meta box callback function
function extrachill_homepage_display_meta_box_callback($post) {
    $show_on_homepage = get_post_meta($post->ID, '_show_on_homepage', true);
    
    // Add nonce field for security
    wp_nonce_field('homepage_display_metabox', 'homepage_display_nonce');
    ?>
    <p>
        <label for="show_on_homepage">
            <input type="checkbox" name="_show_on_homepage" id="show_on_homepage" value="1" <?php checked($show_on_homepage, '1'); ?> />
            <?php esc_html_e('Show on Homepage', 'extra-chill-community'); ?>
        </label>
        <br />
        <span class="description"><?php esc_html_e('Display this forum in the homepage forum list.', 'extra-chill-community'); ?></span>
    </p>
    <?php
}


// Function to save the homepage display setting
function save_forum_homepage_display($post_id) {
    // Check if this is an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Verify nonce for security
    if (!isset($_POST['homepage_display_nonce']) || !wp_verify_nonce($_POST['homepage_display_nonce'], 'homepage_display_metabox')) {
        return;
    }
    
    // Check if current user can edit this post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Only process for forum post type
    if (get_post_type($post_id) !== bbp_get_forum_post_type()) {
        return;
    }
    
    if (isset($_POST['_show_on_homepage'])) {
        update_post_meta($post_id, '_show_on_homepage', '1');
    } else {
        delete_post_meta($post_id, '_show_on_homepage');
    }
}
add_action('save_post', 'save_forum_homepage_display');


// One-time migration function to convert old section data
function migrate_forum_section_to_homepage_display() {
    // Check if migration has already been run
    if (get_option('forum_section_migration_complete')) {
        return;
    }
    
    $forums = get_posts([
        'post_type' => bbp_get_forum_post_type(),
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_bbp_forum_section',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    $migrated_count = 0;
    
    foreach ($forums as $forum) {
        $section = get_post_meta($forum->ID, '_bbp_forum_section', true);
        
        // Convert 'top' and 'middle' sections to show on homepage
        if (in_array($section, ['top', 'middle'])) {
            update_post_meta($forum->ID, '_show_on_homepage', '1');
            $migrated_count++;
        }
        
        // Remove the old meta field
        delete_post_meta($forum->ID, '_bbp_forum_section');
    }
    
    // Mark migration as complete
    update_option('forum_section_migration_complete', true);
    
    // Add admin notice
    if ($migrated_count > 0) {
        add_option('forum_migration_notice', sprintf(
            /* translators: %d: number of forums migrated */
            esc_html__('Forum migration complete: %d forums set to display on homepage.', 'extra-chill-community'),
            $migrated_count
        ));
    }
}

// Run migration on admin init
add_action('admin_init', 'migrate_forum_section_to_homepage_display');

// Display migration notice
function display_forum_migration_notice() {
    $notice = get_option('forum_migration_notice');
    if ($notice) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html($notice); ?></p>
        </div>
        <?php
        delete_option('forum_migration_notice');
    }
}
add_action('admin_notices', 'display_forum_migration_notice');