<?php
/**
 * Cross-Site Links - Main Loader
 *
 * Unified cross-site navigation system for the Extra Chill multisite network.
 * Provides taxonomy archive linking, user profile linking, and artist profile linking
 * between sites with content existence verification.
 *
 * @package ExtraChillNetwork
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load cross-site link components.
require_once __DIR__ . '/taxonomy-links.php';
require_once __DIR__ . '/entity-links.php';
require_once __DIR__ . '/renderers.php';
require_once __DIR__ . '/canonical-authority.php';
require_once __DIR__ . '/bridge-instrumentation.php';
require_once __DIR__ . '/internal-linking-candidates.php';

/**
 * Get taxonomy-to-site mapping
 *
 * Defines which sites use which shared taxonomies.
 * Sites are referenced by their logical key (used with ec_get_blog_id).
 *
 * @return array Taxonomy slug => array of site keys
 */
function extrachill_get_taxonomy_site_map() {
	return apply_filters(
		'extrachill_taxonomy_site_map',
		array(
			'venue'    => array( 'main', 'events' ),
			'location' => array( 'main', 'events', 'wire', 'community' ),
			'artist'   => array( 'main', 'events', 'shop', 'artist', 'community' ),
			'festival' => array( 'main', 'events', 'wire', 'community' ),
		)
	);
}

/**
 * Get human-readable labels for sites
 *
 * Used when displaying cross-site links to users.
 *
 * @return array Site key => label
 */
function extrachill_get_site_labels() {
	return apply_filters(
		'extrachill_site_labels',
		array(
			'main'       => __( 'Blog', 'extrachill-network' ),
			'community'  => __( 'Community', 'extrachill-network' ),
			'shop'       => __( 'Shop', 'extrachill-network' ),
			'artist'     => __( 'Artist Platform', 'extrachill-network' ),
			'events'     => __( 'Events', 'extrachill-network' ),
			'newsletter' => __( 'Newsletter', 'extrachill-network' ),
			'docs'       => __( 'Docs', 'extrachill-network' ),
			'wire'       => __( 'News Wire', 'extrachill-network' ),
			'studio'     => __( 'Studio', 'extrachill-network' ),
		)
	);
}

/**
 * Get content-type-specific labels for sites
 *
 * Used in cross-site taxonomy links to describe content types.
 * Example: "Charleston Blog Posts (5)" instead of "Blog (5)"
 *
 * @return array Site key => content type label
 */
function extrachill_get_site_content_type_labels() {
	return apply_filters(
		'extrachill_site_content_type_labels',
		array(
			'main'      => __( 'Blog Posts', 'extrachill-network' ),
			'events'    => __( 'Events', 'extrachill-network' ),
			'shop'      => __( 'Shop', 'extrachill-network' ),
			'wire'      => __( 'Festival Wire', 'extrachill-network' ),
			'artist'    => __( 'Artist Profile', 'extrachill-network' ),
			'community' => __( 'Forum Discussions', 'extrachill-network' ),
		)
	);
}

/**
 * Get current site's logical key
 *
 * Reverse lookup to find the site key for the current blog.
 *
 * @return string|null Site key or null if not in mapping
 */
function extrachill_get_current_site_key() {
	$current_blog_id = get_current_blog_id();
	$blog_ids        = ec_get_blog_ids();

	foreach ( $blog_ids as $key => $blog_id ) {
		if ( $blog_id === $current_blog_id ) {
			return $key;
		}
	}

	return null;
}

// Register display hooks.
add_action( 'extrachill_archive_below_description', 'extrachill_render_cross_site_taxonomy_links' );
add_action( 'extrachill_after_author_bio', 'extrachill_render_cross_site_user_links' );
