<?php
/**
 * Festival Wire Query Filters and Modifications
 *
 * Handles WordPress query modifications for Festival Wire functionality:
 * - Custom query variables for taxonomy filtering
 * - Archive page query modifications
 * - Search and taxonomy archive integration
 * - Homepage and feed exclusions
 *
 * @package ExtraChillNewsWire
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add custom query variables for Festival Wire filtering
 *
 * Registers festival and location as valid query variables
 * for use in Festival Wire archive filtering and URL parameters.
 *
 * @since 0.1.0
 * @param array $query_vars Existing query variables
 * @return array Modified query variables array
 */
function festival_wire_add_query_vars( $query_vars ) {
	$query_vars[] = 'festival';
	$query_vars[] = 'location';
	return $query_vars;
}
add_filter( 'query_vars', 'festival_wire_add_query_vars' );

/**
 * Modify Festival Wire archive query for taxonomy filtering
 *
 * Applies taxonomy filters on Festival Wire archive pages based on
 * URL query parameters. Supports multiple taxonomy filtering with AND logic.
 *
 * @since 0.1.0
 * @param WP_Query $query The WordPress query object
 * @return WP_Query Modified query object
 */
function festival_wire_modify_archive_query( $query ) {
	// Target only Festival Wire archive pages on frontend
	if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'festival_wire' ) ) {

		// Configure pagination for archive pages
		$query->set( 'posts_per_page', 12 );

		// Build taxonomy query clauses based on URL parameters
		$tax_query_clauses = array();

		// Festival taxonomy filtering
		$festival = get_query_var( 'festival' );
		if ( ! empty( $festival ) ) {
			$tax_query_clauses[] = array(
				'taxonomy' => 'festival',
				'field'    => 'slug',
				'terms'    => $festival,
			);
		}

		// Location taxonomy filtering
		$location = get_query_var( 'location' );
		if ( ! empty( $location ) ) {
			$tax_query_clauses[] = array(
					'taxonomy' => 'location',
				'field'    => 'slug',
				'terms'    => $location,
			);
		}

		// Apply taxonomy query if filters are active
		if ( ! empty( $tax_query_clauses ) ) {
			$final_tax_query = $tax_query_clauses;

			// Use AND logic for multiple taxonomy filters
			if ( count( $tax_query_clauses ) > 1 ) {
				$final_tax_query = array( 'relation' => 'AND' ) + $tax_query_clauses;
			}

			$query->set( 'tax_query', $final_tax_query );
		}
	}

	return $query;
}
add_action( 'pre_get_posts', 'festival_wire_modify_archive_query' );


/**
 * Include Festival Wire posts in WordPress archives and search
 *
 * Integrates Festival Wire posts into search results and author archives,
 * while excluding from homepage and custom feeds. Maintains content
 * discoverability across the site.
 *
 * @since 0.1.0
 * @param WP_Query $query The WordPress query object
 */
function festival_wire_include_in_archives( $query ) {
    // Skip admin, non-main queries, and Festival Wire specific pages
    if ( is_admin() || ! $query->is_main_query() || is_post_type_archive( 'festival_wire' ) ) {
        return;
    }

	// Exclude Festival Wire when another integration explicitly adds it to the homepage.
	if ( $query->is_home() ) {
		$post_types = $query->get( 'post_type' );
        // Remove Festival Wire from post type arrays
        if ( is_array( $post_types ) && in_array( 'festival_wire', $post_types ) ) {
            $post_types = array_diff( $post_types, array( 'festival_wire' ) );
            $query->set( 'post_type', $post_types );
        } elseif ( is_string( $post_types ) && $post_types === 'festival_wire' ) {
             $query->set( 'post_type', 'post' );
        }
	}

	// Exclude from the custom aggregate feed independently of homepage context.
	if ( 'all' === $query->get( 'feed_type' ) ) {
		$post_types = $query->get( 'post_type' );
		if ( is_array( $post_types ) && in_array( 'festival_wire', $post_types, true ) ) {
			$query->set( 'post_type', array_values( array_diff( $post_types, array( 'festival_wire' ) ) ) );
		} elseif ( 'festival_wire' === $post_types ) {
			$query->set( 'post_type', 'post' );
		}
	}

	// Include Festival Wire on location taxonomy archives.
	if ( $query->is_tax( 'location' ) ) {
		$post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) || 'any' === $post_types ) {
			$query->set( 'post_type', array( 'post', 'festival_wire' ) );
		} elseif ( is_string( $post_types ) && 'post' === $post_types ) {
			$query->set( 'post_type', array( 'post', 'festival_wire' ) );
		} elseif ( is_array( $post_types ) && ! in_array( 'festival_wire', $post_types, true ) ) {
			$post_types[] = 'festival_wire';
			$query->set( 'post_type', $post_types );
		}
	}

	// Include Festival Wire in author archives only
	elseif ( $query->is_author() ) {
        $post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) || $post_types === 'any' ) {
			$query->set( 'post_type', array( 'post', 'festival_wire' ) );
		} elseif ( is_string( $post_types ) && $post_types === 'post' ) {
			$query->set( 'post_type', array( 'post', 'festival_wire' ) );
        } elseif ( is_array($post_types) && ! in_array( 'festival_wire', $post_types ) ) {
            $post_types[] = 'festival_wire';
            $query->set( 'post_type', $post_types );
        }
    }
}
add_action( 'pre_get_posts', 'festival_wire_include_in_archives' );
