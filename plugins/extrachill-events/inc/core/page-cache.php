<?php
/**
 * Targeted full-page cache invalidation for event changes.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'extrachill_cache_post_change_urls', 'extrachill_events_cache_invalidation_urls', 10, 3 );

/**
 * Return event pages affected by an event save.
 *
 * This integration keeps Events-specific URL knowledge out of the generic
 * cache layer. Running on both pre_post_update and save_post captures old and
 * new taxonomy assignments when an event moves between terms.
 *
 * @param null|array $urls      URLs supplied by another integration.
 * @param int        $post_id   Changed post ID.
 * @param string     $post_type Changed post type.
 * @return null|array Exact URLs to invalidate, or null for the default purge.
 */
function extrachill_events_cache_invalidation_urls( $urls, $post_id, $post_type ) {
	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) || DATA_MACHINE_EVENTS_POST_TYPE !== $post_type ) {
		return $urls;
	}

	$urls = array(
		home_url( '/' ),
		home_url( '/all/' ),
		home_url( '/location/' ),
		get_permalink( $post_id ),
		get_post_type_archive_link( $post_type ),
	);

	foreach ( array( 'location', 'venue', 'artist', 'festival' ) as $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term ) {
			$term_url = get_term_link( $term );
			if ( is_wp_error( $term_url ) ) {
				continue;
			}

			$urls[] = $term_url;
			if ( 'location' === $taxonomy ) {
				foreach ( array( 'tonight', 'this-weekend', 'this-week' ) as $scope ) {
					$urls[] = trailingslashit( $term_url ) . $scope . '/';
				}
			}
		}
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}
