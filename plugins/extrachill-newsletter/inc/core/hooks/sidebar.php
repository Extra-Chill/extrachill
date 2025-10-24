<?php
/**
 * Newsletter Sidebar Integration
 *
 * Handles sidebar widget display for recent newsletters.
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sidebar Recent Newsletters Hook Function
 *
 * Loads recent newsletters sidebar widget template.
 *
 * @since 1.0.0
 */
function extrachill_sidebar_recent_newsletters() {
	include EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/core/templates/recent-newsletters.php';
}
add_action( 'extrachill_sidebar_bottom', 'extrachill_sidebar_recent_newsletters', 10 );
