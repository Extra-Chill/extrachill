<?php
/**
 * bbPress Template Integration
 *
 * Registers custom template stack with bbPress for template discovery and overrides.
 * Provides homepage override for community.extrachill.com (blog ID 2) only.
 *
 * Template Stack:
 * - Location: /bbpress/ directory (70+ custom templates)
 * - Registration: bbp_register_template_stack() enables bbPress template discovery
 * - Priority: Custom templates override bbPress defaults when present
 *
 * Homepage Override:
 * - Community blog restriction prevents conflicts on other multisite installations
 * - Routes to inc/home/forum-homepage.php, the feed-first homepage
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

function extrachill_community_get_bbpress_template_path() {
	return EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'bbpress';
}

/**
 * Register custom template location with bbPress
 *
 * Allows bbPress to discover and use custom templates in /bbpress/ directory.
 */
function extrachill_community_register_bbpress_templates() {
	bbp_register_template_stack('extrachill_community_get_bbpress_template_path', 1);
}
add_action('bbp_register_theme_packages', 'extrachill_community_register_bbpress_templates');

/**
 * Render homepage content for community site
 *
 * Routes to the feed-first homepage (inc/home/forum-homepage.php): the
 * "What's Happening" activity feed leads, with a demoted "Browse rooms"
 * chip row in place of the legacy [bbp-forum-index] directory table.
 *
 * Hooked via extrachill_homepage_content action. Restricted to the community
 * blog so it never renders on other multisite installs.
 */
function extrachill_community_render_homepage() {
	$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;
	if ( ! $community_blog_id || get_current_blog_id() !== $community_blog_id ) {
		return;
	}

	include EXTRACHILL_COMMUNITY_PLUGIN_DIR . 'inc/home/forum-homepage.php';
}
add_action('extrachill_homepage_content', 'extrachill_community_render_homepage', 10);

/**
 * Suppress bbPress forum statistics notice
 *
 * Returns empty string to hide template-level statistics display.
 *
 * @param string $description Forum description
 * @return string Empty string
 */
add_filter( 'bbp_get_single_forum_description', '__return_empty_string' );

/**
 * Suppress bbPress breadcrumbs network-wide
 *
 * The theme's extrachill_breadcrumbs() already renders the breadcrumb trail
 * (see inc/core/breadcrumb-filter.php). Returning true short-circuits
 * bbp_breadcrumb() everywhere (bbpress/includes/common/template.php), so
 * individual template overrides no longer need to drop bbp_breadcrumb()
 * line-by-line.
 */
add_filter( 'bbp_no_breadcrumb', '__return_true' );
