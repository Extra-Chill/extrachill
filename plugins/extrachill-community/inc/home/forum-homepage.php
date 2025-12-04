<?php
/**
 * Community Forum Homepage Content
 *
 * Homepage content for community.extrachill.com.
 * Hooked via extrachill_homepage_content action.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

extrachill_breadcrumbs();

do_action('extrachill_community_home_header');

do_action('extrachill_community_home_top');

// Output bbPress forum index via shortcode
// This will use the plugin's loop-forums.php template (registered via template stack)
echo do_shortcode('[bbp-forum-index]');

do_action('extrachill_community_home_after_forums');
