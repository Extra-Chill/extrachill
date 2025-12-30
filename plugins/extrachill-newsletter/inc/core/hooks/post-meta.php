<?php
/**
 * Newsletter Post Meta Integration
 *
 * Customizes theme post meta rendering for newsletter posts.
 * - Removes author output ("by ...")
 * - Changes published prefix to "Sent on"
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'extrachill_post_meta_parts',
	function( $parts, $post_id, $post_type ) {
		if ( $post_type !== 'newsletter' ) {
			return $parts;
		}

		return array( 'published' );
	},
	10,
	3
);

add_filter(
	'extrachill_post_meta_published_prefix',
	function( $prefix, $post_id, $post_type ) {
		if ( $post_type !== 'newsletter' ) {
			return $prefix;
		}

		return __( 'Sent on ', 'extrachill-newsletter' );
	},
	10,
	3
);
