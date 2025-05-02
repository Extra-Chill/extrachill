<?php
/**
 * Query Filters and Modifications for Festival Wire CPT.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add custom query vars for festival filtering.
 */
function festival_wire_add_query_vars( $query_vars ) {
	$query_vars[] = 'festival';
	$query_vars[] = 'location';
	$query_vars[] = 'data_source';
	return $query_vars;
}
add_filter( 'query_vars', 'festival_wire_add_query_vars' );

/**
 * Modify the main query for festival wire archive page filtering.
 */
function festival_wire_modify_archive_query( $query ) {
	// Only modify the main query on the frontend for the festival_wire archive
	if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'festival_wire' ) ) {

		// Set default posts per page for the archive
		$query->set( 'posts_per_page', 12 );

		// Initialize tax_query array
		$tax_query_clauses = array();

		// Check if we have a festival filter
		$festival = get_query_var( 'festival' );
		if ( ! empty( $festival ) ) {
			// Add the festival taxonomy query clause
			$tax_query_clauses[] = array(
				'taxonomy' => 'festival',
				'field'    => 'slug',
				'terms'    => $festival,
			);
		}

		// Check if we have a location filter
		$location = get_query_var( 'location' );
		if ( ! empty( $location ) ) {
			// Add the location taxonomy query clause
			$tax_query_clauses[] = array(
				'taxonomy' => 'location',
				'field'    => 'slug',
				'terms'    => $location,
			);
		}

		// Check if we have a data source filter
		$data_source = get_query_var( 'data_source' );
		if ( ! empty( $data_source ) ) {
			// Add the data_source taxonomy query clause
			$tax_query_clauses[] = array(
				'taxonomy' => 'data_source',
				'field'    => 'slug',
				'terms'    => $data_source,
			);
		}

		// Apply the tax_query if any clauses were added
		if ( ! empty( $tax_query_clauses ) ) {
			// Prepare the final tax_query array
			$final_tax_query = $tax_query_clauses; // Start with the clauses

			// Set the relation to 'AND' at the top level if there are multiple clauses
			if ( count( $tax_query_clauses ) > 1 ) {
				$final_tax_query = array( 'relation' => 'AND' ) + $tax_query_clauses;
			}

			// Get existing tax_query (if any) and decide how to merge or override
			// For this case, we are overriding any previous tax_query with our specific archive filters.
			// If you needed to combine with other pre_get_posts filters, merging logic would be needed here.
			$query->set( 'tax_query', $final_tax_query );
		}
	}

	return $query;
}
add_action( 'pre_get_posts', 'festival_wire_modify_archive_query' );


/**
 * Modify global queries to include 'festival_wire' post type where needed.
 */
function festival_wire_include_in_archives( $query ) {
    // Bail if this is the admin area, not the main query, or already a festival_wire specific query
    if ( is_admin() || ! $query->is_main_query() || is_post_type_archive( 'festival_wire' ) ) {
        return;
    }

    // Exclude from homepage/front page
    if ( $query->is_home() && $query->is_main_query() ) {
        // Get existing post types
        $post_types = $query->get( 'post_type' );
        if ( is_array( $post_types ) && in_array( 'festival_wire', $post_types ) ) {
            // Remove festival_wire if it exists
            $post_types = array_diff( $post_types, array( 'festival_wire' ) );
            $query->set( 'post_type', $post_types );
        } elseif ( is_string( $post_types ) && $post_types === 'festival_wire' ) {
             // If it was the only one, set to default 'post'
             $query->set( 'post_type', 'post' );
        } elseif ( empty( $post_types ) ) {
			// If it was empty (meaning default 'post'), we don't need to do anything as festival_wire wasn't included.
		}

		// Note: Also explicitly excluding from any custom 'all' feed if identifiable.
        // If your 'all' feed uses a specific query parameter or condition, add it here.
        // For example, if it uses a specific meta query or taxonomy term:
        // if ( $query->get('meta_key') === 'custom_all_feed_marker' ) { /* handle exclusion */ }
        // Add a check for your 'all' feed. Let's assume it's identified by a query var 'feed_type' == 'all'
        if ( $query->get( 'feed_type' ) === 'all' ) {
             $post_types = $query->get( 'post_type' );
             if ( is_array( $post_types ) && in_array( 'festival_wire', $post_types ) ) {
                 $post_types = array_diff( $post_types, array( 'festival_wire' ) );
                 $query->set( 'post_type', $post_types );
             } elseif ( is_string( $post_types ) && $post_types === 'festival_wire' ) {
                 $query->set( 'post_type', 'post' ); // Or whatever the default should be for the 'all' feed
             }
             // If 'any' or empty, might need specific handling depending on the 'all' feed's desired default behavior
        }
    }

    // Include in search results
    elseif ( $query->is_search() ) {
        $post_types = $query->get( 'post_type' );

        // If post_type is empty or 'any', or explicitly includes 'post'
        if ( empty($post_types) || $post_types === 'any' || (is_string($post_types) && $post_types == 'post') || (is_array($post_types) && in_array('post', $post_types)) ) {
            if ( empty($post_types) || $post_types === 'any' ) {
                // If searching 'any', explicitly list default types + festival_wire
                // You might need to adjust this list based on your site's registered post types
                $search_types = array('post', 'page', 'attachment'); // Start with common defaults
                // Add other public CPTs if they should be searched by default
                // Example: $search_types[] = 'other_cpt';
                $search_types[] = 'festival_wire';
            } elseif ( is_string($post_types) ) {
                $search_types = array( $post_types, 'festival_wire' );
            } elseif ( is_array( $post_types ) && ! in_array( 'festival_wire', $post_types ) ) {
                // Add festival_wire if it's not already included
                $search_types = array_merge( $post_types, array( 'festival_wire' ) );
            } else {
				$search_types = $post_types; // Already includes festival_wire or is some other specific type
			}
            $query->set( 'post_type', $search_types );
        }
    }

    // Include in default taxonomy archives (categories, tags)
    elseif ( ( $query->is_category() || $query->is_tag() ) && $query->is_main_query() ) {
        $post_types = $query->get( 'post_type' );

        if ( empty($post_types) || $post_types === 'any' ) {
            // Default is usually 'post', so add 'festival_wire'
            $query->set( 'post_type', array( 'post', 'festival_wire' ) );
        } elseif ( is_string($post_types) && $post_types == 'post' ) {
            // If explicitly 'post', add 'festival_wire'
            $query->set( 'post_type', array( 'post', 'festival_wire' ) );
        } elseif ( is_array($post_types) && ! in_array( 'festival_wire', $post_types ) ) {
            // If it's an array not including 'festival_wire', add it
            $post_types[] = 'festival_wire';
            $query->set( 'post_type', $post_types );
        }
        // If 'festival_wire' is already included, or it's set to something other than 'post' or 'any', leave it.
    }
}
add_action( 'pre_get_posts', 'festival_wire_include_in_archives' ); 