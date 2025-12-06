<?php
/**
 * Plugin Name: Extra Chill Community
 * Description: bbPress extension plugin providing community and forum functionality for the Extra Chill platform.
 * Version: 1.0.7
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
 * Loads 34 feature files via direct require_once.
 * Load order: core (7) → content (6) → social (11) → user-profiles (7) → home (4).
 * Templates loaded via hooks: forum-home-header, forum-homepage, recently-active.
 * Note: Avatar display functionality in extrachill-users plugin; upload UI here for bbPress integration.
 */
function extrachill_community_init() {
    // Core functionality (6 files)
    require_once plugin_dir_path(__FILE__) . 'inc/core/assets.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/bbpress-templates.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/breadcrumb-filter.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/page-templates.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/bbpress-spam-adjustments.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/sidebar.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/nav.php';

    // Content features (6 files)
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/tinymce-customization.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/tinymce-image-uploads.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/content-filters.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/recent-feed.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/main-site-comments.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/subforum-button-classes.php';

    // Social features (11 files)
    require_once plugin_dir_path(__FILE__) . 'inc/social/upvote.php';
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

    // User profile features (7 files)
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/custom-user-profile.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/verification.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/settings/settings-content.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/settings/settings-form-handler.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/edit/user-links.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/edit/user-info.php';
    require_once plugin_dir_path(__FILE__) . 'inc/user-profiles/edit/avatar-upload.php';

    // Homepage features (4 files)
    require_once plugin_dir_path(__FILE__) . 'inc/home/latest-post.php';
    require_once plugin_dir_path(__FILE__) . 'inc/home/actions.php';
    require_once plugin_dir_path(__FILE__) . 'inc/home/homepage-forum-display.php';
    require_once plugin_dir_path(__FILE__) . 'inc/home/artist-platform-buttons.php';
}
add_action('plugins_loaded', 'extrachill_community_init');