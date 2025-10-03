<?php
/**
 * Community Forum Homepage Template
 *
 * Template component loaded via extrachill_template_homepage filter (not via extrachill_community_init).
 * Provides the bbPress forum index as the homepage for community.extrachill.com.
 * Registered by inc/home/homepage-forum-display.php.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

do_action('extrachill_community_home_header');

do_action('extrachill_community_home_top');

// Output bbPress forum index via shortcode
// This will use the plugin's loop-forums.php template (registered via template stack)
echo do_shortcode('[bbp-forum-index]');

do_action('extrachill_community_home_after_forums');

get_footer();
