<?php

/**
 * Custom Search Functionality
 * Merges search results from the local database with forum results from a remote bbPress instance.
 *
 * --- How It Works ---
 * 1.  The `ec_hijack_search_query` function hooks into `posts_pre_query`. This hook runs before the main SQL query for posts.
 * 2.  It checks if this is a main search query on the frontend. If not, it returns, letting WordPress proceed as normal.
 * 3.  It checks for a cached result (transient) for the given search term to avoid expensive operations on every load.
 * 4.  If no cache is found:
 *     a. It fetches ALL relevant posts from the community forum using multisite functions (`ec_fetch_forum_results`).
 *     b. It runs a separate, new WP_Query to get ALL relevant posts from the local database.
 *     c. It merges the local posts and the virtual forum posts into a single array.
 *     d. It sorts this master array by date, descending.
 *     e. It stores this complete, sorted array in a transient.
 * 5.  It takes the final merged list (from cache or newly generated) and tells the main query object the total number of found posts (`$query->found_posts`) and the correct number of pages (`$query->max_num_pages`). This makes WordPress pagination functions work correctly.
 * 6.  It then manually paginates the master array using `array_slice` to get just the posts for the current page.
 * 7.  Finally, it returns this paginated slice of posts to the main query, which then uses them in the search results loop. The original database query is never run.
 * 8.  Helper filters (`post_link`, `the_permalink`) ensure that the virtual forum posts link back to the correct community.extrachill.com URLs.
 */

// Hook into `posts_pre_query` to supply our own custom-merged results.
add_filter( 'posts_pre_query', 'ec_hijack_search_query', 10, 2 );
function ec_hijack_search_query( $posts, $query ) {

	// Only act on the main search query on the frontend.
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return $posts; // Let WordPress handle it.
	}

	$search_term    = $query->get( 's' );
	$paged          = max( 1, $query->get( 'paged' ) );
	$posts_per_page = $query->get( 'posts_per_page' );
	$transient_key  = 'ec_search_results_v2_' . md5( $search_term );

	// Try to get merged results from cache.
	$merged_posts = get_transient( $transient_key );

	if ( false === $merged_posts ) {
		// --- CACHE MISS ---

		// 1. Fetch remote forum results.
		$remote_data = ec_fetch_forum_results( $search_term, 100 ); // Fetch a generous amount.
		$fake_posts  = ec_process_remote_results( $remote_data );

		// 2. Fetch local WordPress results.
		$local_posts = ec_fetch_local_results( $search_term );

		// 3. Merge and sort all results.
		$merged_posts = array_merge( $local_posts, $fake_posts );
		usort(
			$merged_posts,
			function( $a, $b ) use ( $search_term ) {
				// --- Advanced Relevance Scoring ---
				$a_title = $a->post_title;
				$b_title = $b->post_title;
				$a_score = 0;
				$b_score = 0;

				// Score 2: Exact, case-insensitive title match.
				if ( strcasecmp( $a_title, $search_term ) === 0 ) { $a_score = 2; }
				if ( strcasecmp( $b_title, $search_term ) === 0 ) { $b_score = 2; }

				// Score 1: Partial, case-insensitive title match.
				if ( $a_score === 0 && false !== stripos( $a_title, $search_term ) ) { $a_score = 1; }
				if ( $b_score === 0 && false !== stripos( $b_title, $search_term ) ) { $b_score = 1; }

				// --- Sorting Logic ---
				// If scores differ, the higher score wins.
				if ( $a_score !== $b_score ) {
					return $b_score - $a_score; // Sorts descending (2, 1, 0).
				}

				// If scores are equal, fall back to sorting by date (newest first).
				return strcmp( $b->post_date, $a->post_date );
			}
		);

		// 4. Cache the merged results for 10 minutes.
		set_transient( $transient_key, $merged_posts, 10 * MINUTE_IN_SECONDS );
	}

	// --- PAGINATION LOGIC ---

	// 5. Inform the main query of the correct total number of posts and pages.
	$query->found_posts   = count( $merged_posts );
	$query->max_num_pages = ceil( $query->found_posts / $posts_per_page );

	// 6. Manually slice the array to get the posts for the current page.
	$offset = ( $paged - 1 ) * $posts_per_page;
	return array_slice( $merged_posts, $offset, $posts_per_page );
}


/**
 * Wrapper function for forum search results
 * Maintains compatibility while delegating to multisite implementation
 *
 * This function serves as the interface used by the search hijack system
 * while the actual implementation uses native multisite functions for performance
 *
 * @param string $search_term The search term to query
 * @param int    $limit       Maximum number of results to return (default: 100)
 * @return array Array of forum search results from multisite query
 * @since 1.0
 */
function ec_fetch_forum_results( $search_term, $limit = 100 ) {
	return ec_fetch_forum_results_multisite( $search_term, $limit );
}

/**
 * Fetches all matching search results from the local database.
 */
function ec_fetch_local_results( $search_term ) {
	$local_args = array(
		's'              => $search_term,
		'post_type'      => array( 'post', 'page', 'product' ),
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	);
	$local_query = new WP_Query( $local_args );
	return $local_query->posts;
}

/**
 * Processes remote data and creates virtual WP_Post objects.
 * Includes deduplication logic for topics and replies.
 */
function ec_process_remote_results( $remote_data ) {
	if ( empty( $remote_data ) ) {
		return array();
	}

	$topics     = [];
	$fake_posts = [];

	// Deduplicate topics to show only the most recent activity (topic or reply).
	foreach ( $remote_data as $item ) {
		if ( empty( $item['id'] ) || empty( $item['title'] ) ) {
			continue;
		}

		// Use the forum link's MD5 as a unique ID for the parent topic.
		$topic_id = isset( $item['forum']['link'] ) ? md5( $item['forum']['link'] ) : null;
		if ( ! $topic_id ) {
			continue;
		}

		// If this is a reply, only keep it if it's newer than the existing entry for this topic.
		if ( 'reply' === $item['type'] ) {
			if ( ! isset( $topics[ $topic_id ] ) || strtotime( $topics[ $topic_id ]['date'] ) < strtotime( $item['date'] ) ) {
				$topics[ $topic_id ] = $item; // This is the latest activity.
			}
		} else { // It's a topic.
			if ( ! isset( $topics[ $topic_id ] ) ) {
				$topics[ $topic_id ] = $item;
			}
		}
	}

	foreach ( $topics as $item ) {
		$post_obj = (object) array(
			'ID'             => 900000000 + (int) $item['id'],
			'post_title'     => $item['title'],
			'post_content'   => $item['excerpt'],
			'post_excerpt'   => highlight_search_term( $item['excerpt'], get_search_query() ),
			'post_date'      => $item['date'],
			'post_date_gmt'  => get_gmt_from_date( $item['date'] ),
			'post_type'      => 'post', // Treat as post for template compatibility.
			'post_status'    => 'publish',
			'guid'           => $item['link'], // This will be used as the permalink.
			'post_author'    => 1,
			'filter'         => 'raw',
			'_is_forum_post' => true,
			'_author'        => $item['author'] ?? 'Unknown',
			'_forum'         => $item['forum'] ?? array( 'title' => 'Unknown Forum', 'link' => '#' ),
		);
		$fake_posts[] = new WP_Post( $post_obj );
	}
	return $fake_posts;
}


/**
 * Overrides the permalink for our virtual forum posts.
 */
add_filter( 'post_link', 'ec_override_permalink_for_remote_posts', 10, 2 );
add_filter( 'the_permalink', 'ec_override_permalink_for_remote_posts', 10, 2 ); // Also filter the_permalink for broader compatibility.
function ec_override_permalink_for_remote_posts( $url, $post ) {
	// Our virtual posts have a custom property '_is_forum_post'.
	if ( isset( $post->_is_forum_post ) && $post->_is_forum_post ) {
		return $post->guid; // The guid was set to the correct remote URL.
	}
	return $url;
}

/**
 * Highlights the search term in a given string.
 */
function highlight_search_term( $excerpt, $search_term ) {
	if ( empty( $search_term ) ) {
		return $excerpt;
	}
	// Use preg_quote to escape special regex characters in the search term.
	$escaped_search_term = preg_quote( $search_term, '/' );
	// Perform a case-insensitive replacement.
	return preg_replace( "/($escaped_search_term)/i", '<b>$1</b>', $excerpt );
}
