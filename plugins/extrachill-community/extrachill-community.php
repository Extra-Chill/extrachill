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

function extrachill_community_init() {
    // Core functionality
    require_once plugin_dir_path(__FILE__) . 'inc/core/assets.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/bbpress-templates.php';
    require_once plugin_dir_path(__FILE__) . 'inc/core/nav.php';

    // Admin features
    require_once plugin_dir_path(__FILE__) . 'inc/admin/bbpress-spam-adjustments.php';
    require_once plugin_dir_path(__FILE__) . 'inc/admin/forum-sections.php';

    // Content features
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/tinymce-customization.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/tinymce-image-uploads.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/editor/topic-quick-reply.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/content-filters.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/quote.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/queries/homepage-queries.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/queries/recent-feed-queries.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/sorting.php';
    require_once plugin_dir_path(__FILE__) . 'inc/content/recent-feed.php';

    // Social features
    require_once plugin_dir_path(__FILE__) . 'inc/social/upvote.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/user-mention-api.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/forum-badges.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/rank-system/point-calculation.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/rank-system/chill-forums-rank.php';
    require_once plugin_dir_path(__FILE__) . 'inc/social/notifications.php';

    // User features
    require_once plugin_dir_path(__FILE__) . 'inc/users/custom-avatar.php';
    require_once plugin_dir_path(__FILE__) . 'inc/users/custom-user-profile.php';
    require_once plugin_dir_path(__FILE__) . 'inc/users/verification.php';
    require_once plugin_dir_path(__FILE__) . 'inc/users/settings/settings-content.php';
    require_once plugin_dir_path(__FILE__) . 'inc/users/settings/settings-form-handler.php';
    require_once plugin_dir_path(__FILE__) . 'inc/users/online-users-count.php';
    require_once plugin_dir_path(__FILE__) . 'inc/users/email-change-emails.php';

    // Homepage features
    require_once plugin_dir_path(__FILE__) . 'inc/home/actions.php';
}
add_action('plugins_loaded', 'extrachill_community_init');