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
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add custom query variables for Festival Wire filtering
 *
 * Registers festival, location, and data_source as valid query variables
 * for use in Festival Wire archive filtering and URL parameters.
 *
 * @since 1.0.0
 * @param array $query_vars Existing query variables
 * @return array Modified query variables array
 */
function festival_wire_add_query_vars( $query_vars ) {
	$query_vars[] = 'festival';
	$query_vars[] = 'location';
	$query_vars[] = 'data_source';
	return $query_vars;
}
add_filter( 'query_vars', 'festival_wire_add_query_vars' );

/**
 * Modify Festival Wire archive query for taxonomy filtering
 *
 * Applies taxonomy filters on Festival Wire archive pages based on
 * URL query parameters. Supports multiple taxonomy filtering with AND logic.
 *
 * @since 1.0.0
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

		// Data source taxonomy filtering
		$data_source = get_query_var( 'data_source' );
		if ( ! empty( $data_source ) ) {
			$tax_query_clauses[] = array(
				'taxonomy' => 'data_source',
				'field'    => 'slug',
				'terms'    => $data_source,
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
 * Integrates Festival Wire posts into search results, category/tag archives,
 * while excluding from homepage and custom feeds. Maintains content
 * discoverability across the site.
 *
 * @since 1.0.0
 * @param WP_Query $query The WordPress query object
 */
function festival_wire_include_in_archives( $query ) {
    // Skip admin, non-main queries, and Festival Wire specific pages
    if ( is_admin() || ! $query->is_main_query() || is_post_type_archive( 'festival_wire' ) ) {
        return;
    }

    // Exclude Festival Wire from homepage and custom feeds
    if ( $query->is_home() && $query->is_main_query() ) {
        $post_types = $query->get( 'post_type' );
        // Remove Festival Wire from post type arrays
        if ( is_array( $post_types ) && in_array( 'festival_wire', $post_types ) ) {
            $post_types = array_diff( $post_types, array( 'festival_wire' ) );
            $query->set( 'post_type', $post_types );
        } elseif ( is_string( $post_types ) && $post_types === 'festival_wire' ) {
             $query->set( 'post_type', 'post' );
        }

		// Exclude from custom 'all' feeds
        if ( $query->get( 'feed_type' ) === 'all' ) {
             $post_types = $query->get( 'post_type' );
             if ( is_array( $post_types ) && in_array( 'festival_wire', $post_types ) ) {
                 $post_types = array_diff( $post_types, array( 'festival_wire' ) );
                 $query->set( 'post_type', $post_types );
             } elseif ( is_string( $post_types ) && $post_types === 'festival_wire' ) {
                 $query->set( 'post_type', 'post' );
             }
        }
    }

    // Include Festival Wire in search results
    elseif ( $query->is_search() ) {
        $post_types = $query->get( 'post_type' );

        // Add to search when default post types are being searched
        if ( empty($post_types) || $post_types === 'any' || (is_string($post_types) && $post_types == 'post') || (is_array($post_types) && in_array('post', $post_types)) ) {
            if ( empty($post_types) || $post_types === 'any' ) {
                // Default search includes posts, pages, and Festival Wire
                $search_types = array('post', 'page', 'attachment');
                $search_types[] = 'festival_wire';
            } elseif ( is_string($post_types) ) {
                $search_types = array( $post_types, 'festival_wire' );
            } elseif ( is_array( $post_types ) && ! in_array( 'festival_wire', $post_types ) ) {
                $search_types = array_merge( $post_types, array( 'festival_wire' ) );
            } else {
				$search_types = $post_types;
			}
            $query->set( 'post_type', $search_types );
        }
    }

    // Include Festival Wire in category and tag archives
    elseif ( ( $query->is_category() || $query->is_tag() ) && $query->is_main_query() ) {
        $post_types = $query->get( 'post_type' );

        if ( empty($post_types) || $post_types === 'any' ) {
            $query->set( 'post_type', array( 'post', 'festival_wire' ) );
        } elseif ( is_string($post_types) && $post_types == 'post' ) {
            $query->set( 'post_type', array( 'post', 'festival_wire' ) );
        } elseif ( is_array($post_types) && ! in_array( 'festival_wire', $post_types ) ) {
            $post_types[] = 'festival_wire';
            $query->set( 'post_type', $post_types );
        }
    }
}
add_action( 'pre_get_posts', 'festival_wire_include_in_archives' ); 