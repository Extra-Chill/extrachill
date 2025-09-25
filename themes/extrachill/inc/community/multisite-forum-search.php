<?php

/**
 * Multisite Forum Search Functions
 * Direct multisite queries for forum search functionality
 * Replaces REST API calls with native WordPress multisite functions
 *
 * @package ExtraChill
 * @since 1.0
 */

/**
 * Fetches search results from the community forum using multisite functions
 * Direct replacement for REST API call - provides significant performance improvement
 * by using native WordPress database queries instead of HTTP requests
 *
 * @param string $search_term The search term to search for in topics and replies
 * @param int    $limit       Maximum number of results to fetch (default: 100)
 * @return array Array of forum search results with contextual excerpts
 * @since 1.0
 *
 * @throws Exception If multisite operation fails, logs error and returns empty array
 */
function ec_fetch_forum_results_multisite( $search_term, $limit = 100 ) {
	if ( empty( $search_term ) ) {
		return array();
	}

	if ( ! is_multisite() ) {
		error_log( 'Forum search multisite error: WordPress multisite not detected' );
		return array();
	}

	$results = array();

	// Switch to community site (blog ID 2)
	switch_to_blog( 2 );

	try {
		// Query arguments matching the original REST API endpoint logic
		$args = array(
			'post_type'      => array( 'topic', 'reply' ),
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			's'              => $search_term,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_bbp_forum_id',
					'value'   => array( 1494, 547 ),
					'compare' => 'NOT IN',
				),
			),
		);

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();

				$type = get_post_type();
				$forum_id = null;
				$forum_title = 'Unknown Forum';
				$forum_link = '#';

				if ( $type === 'reply' ) {
					$parent_topic_id = get_post_meta( get_the_ID(), '_bbp_topic_id', true );
					$forum_id = get_post_meta( $parent_topic_id, '_bbp_forum_id', true );
				} elseif ( $type === 'topic' ) {
					$forum_id = get_post_meta( get_the_ID(), '_bbp_forum_id', true );
				}

				if ( $forum_id ) {
					$forum_title = get_the_title( $forum_id );
					$forum_link = get_permalink( $forum_id );
				}

				// For replies, construct the URL manually using WordPress native functions
				$post_url = get_permalink();
				if ( $type === 'reply' ) {
					$post_url = get_permalink() . '#post-' . get_the_ID();
				}

				$results[] = array(
					'id'         => get_the_ID(),
					'guid'       => $post_url,
					'type'       => $type,
					'title'      => get_the_title(),
					'link'       => $post_url,
					'excerpt'    => ec_get_contextual_excerpt_multisite( wp_strip_all_tags( get_the_content() ), $search_term, 30 ),
					'author'     => get_the_author_meta( 'display_name' ),
					'date'       => get_the_date( 'c' ),
					'forum'      => array(
						'title' => $forum_title,
						'link'  => $forum_link,
					),
					'upvotes'    => get_post_meta( get_the_ID(), 'upvote_count', true ) ?: 0,
				);
			}
			wp_reset_postdata();
		}

	} catch ( Exception $e ) {
		// Log error but don't break the search functionality
		error_log( 'Forum search multisite error: ' . $e->getMessage() );
		$results = array();
	} finally {
		// Always restore current blog
		restore_current_blog();
	}

	return $results;
}

/**
 * Generate a contextual excerpt highlighting the search term within content
 * Provides intelligent excerpt generation showing text around the first match
 *
 * @param string $content     The full content to extract excerpt from
 * @param string $search_term The term to highlight and center excerpt around
 * @param int    $word_limit  Number of words to include around the match (default: 30)
 * @return string The contextual excerpt with ellipses as needed
 * @since 1.0
 */
function ec_get_contextual_excerpt_multisite( $content, $search_term, $word_limit = 30 ) {
	$position = stripos( $content, $search_term );
	if ( $position === false ) {
		// If no match, fallback to default trimmed content
		return '...' . wp_trim_words( $content, $word_limit ) . '...';
	}

	$words = explode( ' ', $content );
	$match_position = 0;

	// Count words until we find the match
	foreach ( $words as $index => $word ) {
		if ( stripos( $word, $search_term ) !== false ) {
			$match_position = $index;
			break;
		}
	}

	$start = max( 0, $match_position - floor( $word_limit / 2 ) );
	$length = min( count( $words ) - $start, $word_limit );

	// Extract the excerpt
	$excerpt = array_slice( $words, $start, $length );

	$prefix = $start > 0 ? '...' : '';
	$suffix = ( $start + $length ) < count( $words ) ? '...' : '';

	return $prefix . implode( ' ', $excerpt ) . $suffix;
}