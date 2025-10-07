<?php
/**
 * Plugin Name: Extra Chill Community
 * Description: bbPress extension plugin providing community and forum functionality for the Extra Chill platform.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires Plugins: bbpress
 * Text Domain: extra-chill-community
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EXTRACHILL_COMMUNITY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EXTRACHILL_COMMUNITY_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Initialize plugin functionality
 *
 * Explicit loading architecture - all 36 feature files loaded directly on plugins_loaded.
 * Load order: core (5) → content (4) → social (12) → user-profiles (8) → home (3).
 *
 * Template components NOT loaded here (loaded via include/require or action hooks):
 * - inc/home/forum-home-header.php (action hook: extrachill_community_home_header)
 * - inc/home/forum-homepage.php (template filter: extrachill_template_homepage)
 * - inc/home/recently-active.php (action hook: extrachill_community_home_top)
 */
function extrachill_community_init() {
    // Core functionality (5 files)
    require_once plugin_dir_path(__FILE__) . 'inc/core/assets.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/bbpress-templates.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/nav.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/bbpress-spam-adjustments.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/sidebar.php';

    // Content features (4 files)
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/tinymce-customization.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/tinymce-image-uploads.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/content-filters.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/recent-feed.php';

    // Social features (12 files)
    require_once plugin_dir_path(__FILE__) . 'inc/social/upvote.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/user-mention-api.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/forum-badges.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/rank-system/point-calculation.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/rank-system/chill-forums-rank.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/notification-bell.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/notification-card.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/notification-handler.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/notification-cleanup.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/capture-replies.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/capture-mentions.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications/notifications-content.php';

    // User profile features (8 files) - avatar menu moved to extrachill-multisite
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/custom-avatar.php';
    // Avatar menu now loaded from extrachill-multisite plugin with conditional loading
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/custom-user-profile.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/verification.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/settings/settings-content.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/settings/settings-form-handler.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/online-users-count.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/edit/upload-custom-avatar.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/edit/user-links.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/edit/user-info.php';

    // Homepage features (4 files)
    require_once plugin_dir_path(__FILE__) . 'inc/home/latest-post.php';
    require_once plugin_dir_path(__FILE__) . 'inc/home/actions.php';
    require_once plugin_dir_path(__FILE__) . 'inc/home/homepage-forum-display.php';
    require_once plugin_dir_path(__FILE__) . 'inc/home/artist-platform-buttons.php';
}
add_action('plugins_loaded', 'extrachill_community_init');