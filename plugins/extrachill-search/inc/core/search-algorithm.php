<?php
/**
 * Search Algorithm for ExtraChill Search Plugin
 *
 * Contains the multisite search execution, PHP fallback matching, and
 * relevance scoring routines that operate on the helper utilities from
 * search-functions.php.
 *
 * @package ExtraChill\Search
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Word-level search fallback when WordPress native search returns zero results.
 *
 * Fetches all posts and filters in PHP by checking if ALL search words exist
 * in title or content. Only triggered when WordPress search finds nothing.
 *
 * @param string $search_term Search query.
 * @param array  $blog_ids    Blog IDs to inspect.
 * @param array  $args        Query arguments.
 * @return array Matching search results.
 */
function extrachill_word_level_search_fallback( $search_term, $blog_ids, $args ) {
	$search_words = preg_split( '/\s+/', strtolower( extrachill_normalize_search_term( $search_term ) ), -1, PREG_SPLIT_NO_EMPTY );
	$all_results = array();
	$site_post_types = extrachill_get_site_post_types();

	foreach ( $blog_ids as $blog_id ) {
		// Verify blog exists before switching to prevent state corruption
		$blog_details = get_blog_details( $blog_id );
		if ( ! $blog_details ) {
			continue;
		}

		switch_to_blog( $blog_id );

		try {

			$post_types = isset( $site_post_types[ $blog_id ] )
				? $site_post_types[ $blog_id ]
				: array( 'post', 'page' );

			$post_types = apply_filters( 'extrachill_search_site_post_types', $post_types, $blog_id );

			if ( empty( $post_types ) ) {
				continue;
			}

			$query_args = array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => $args['post_status'],
				'posts_per_page' => -1,
				'orderby'        => $args['orderby'],
				'order'          => $args['order'],
			);

			if ( ! empty( $args['meta_query'] ) ) {
				$query_args['meta_query'] = $args['meta_query'];
			}

			if ( ! empty( $args['tax_query'] ) ) {
				$query_args['tax_query'] = $args['tax_query'];
			}

			$query = new WP_Query( $query_args );

			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					global $post;

					$title_normalized = strtolower( extrachill_normalize_search_term( get_the_title() ) );
					$content_normalized = strtolower( extrachill_normalize_search_term( strip_tags( get_the_content() ) ) );
					$combined = $title_normalized . ' ' . $content_normalized;

					$all_words_found = true;
					foreach ( $search_words as $word ) {
						if ( strpos( $combined, $word ) === false ) {
							$all_words_found = false;
							break;
						}
					}

					if ( ! $all_words_found ) {
						continue;
					}

					$post_title = get_the_title();
					$permalink  = get_permalink();

					if ( $post->post_type === 'reply' && ! empty( $post->post_parent ) ) {
						$topic_id = $post->post_parent;
						$topic_title = get_the_title( $topic_id );

						if ( ! empty( $topic_title ) ) {
							$post_title = 'Re: ' . $topic_title;
							$permalink = get_permalink( $topic_id ) . '#post-' . $post->ID;
						}
					}

					$taxonomies = array();
					$public_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
					foreach ( $public_taxonomies as $taxonomy ) {
						$terms = get_the_terms( $post->ID, $taxonomy->name );
						if ( $terms && ! is_wp_error( $terms ) ) {
							$taxonomies[ $taxonomy->name ] = wp_list_pluck( $terms, 'term_id', 'name' );
						}
					}

					$thumbnail_id = get_post_thumbnail_id( $post->ID );
					$thumbnail_data = array();
					if ( $thumbnail_id ) {
						$thumbnail_metadata = wp_get_attachment_metadata( $thumbnail_id );
						$thumbnail_data = array(
							'thumbnail_id'     => $thumbnail_id,
							'thumbnail_url'    => wp_get_attachment_image_url( $thumbnail_id, 'medium_large' ),
							'thumbnail_srcset' => wp_get_attachment_image_srcset( $thumbnail_id, 'medium_large' ),
							'thumbnail_sizes'  => wp_get_attachment_image_sizes( $thumbnail_id, 'medium_large' ),
							'thumbnail_alt'    => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
							'thumbnail_width'  => isset( $thumbnail_metadata['sizes']['medium_large']['width'] ) ? $thumbnail_metadata['sizes']['medium_large']['width'] : null,
							'thumbnail_height' => isset( $thumbnail_metadata['sizes']['medium_large']['height'] ) ? $thumbnail_metadata['sizes']['medium_large']['height'] : null,
						);
					}

					$result = array(
						'ID'            => $post->ID,
						'post_title'    => $post_title,
						'post_content'  => get_the_content(),
						'post_excerpt'  => has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30 ),
						'post_date'     => $post->post_date,
						'post_modified' => $post->post_modified,
						'post_type'     => $post->post_type,
						'post_name'     => $post->post_name,
						'post_author'   => $post->post_author,
						'site_id'       => $blog_id,
						'site_name'     => $blog_details->blogname,
						'site_url'      => parse_url( $blog_details->siteurl, PHP_URL_HOST ),
						'permalink'     => $permalink,
						'taxonomies'    => $taxonomies,
						'thumbnail'     => $thumbnail_data,
					);

					$all_results[] = $result;
				}
				wp_reset_postdata();
			}
		} catch ( Exception $e ) {
			error_log( sprintf( 'Word-level fallback search error on blog %d: %s', $blog_id, $e->getMessage() ) );
		}

		restore_current_blog();
	}

	return $all_results;
}

/**
 * Search across multisite network with relevance scoring.
 *
 * @param string $search_term Search query.
 * @param array  $site_urls   Optional site URL/domain list to restrict search.
 * @param array  $args        Optional overrides (limit, offset, filters).
 * @return array|array[] Either results array or paginated data when return_count true.
 */
function extrachill_multisite_search( $search_term, $site_urls = array(), $args = array() ) {
	if ( ! is_multisite() ) {
		error_log( 'Multisite search error: WordPress multisite not detected' );
		return array();
	}

	$defaults = array(
		'post_status'  => array( 'publish' ),
		'limit'        => 10,
		'offset'       => 0,
		'meta_query'   => null,
		'orderby'      => 'date',
		'order'        => 'DESC',
		'return_count' => false,
		'tax_query'    => null,
	);

	$args = wp_parse_args( $args, $defaults );
	$args = apply_filters( 'extrachill_search_args', $args, $search_term, $site_urls );

	if ( empty( $site_urls ) ) {
		$network_sites = extrachill_get_network_sites();
		$blog_ids      = wp_list_pluck( $network_sites, 'id' );
	} else {
		$blog_ids = extrachill_resolve_site_urls( $site_urls );
	}

	if ( empty( $blog_ids ) ) {
		return array();
	}

	$all_results = array();
	$site_post_types = extrachill_get_site_post_types();

	foreach ( $blog_ids as $blog_id ) {
		// Verify blog exists before switching to prevent state corruption
		$blog_details = get_blog_details( $blog_id );
		if ( ! $blog_details ) {
			continue;
		}

		switch_to_blog( $blog_id );

		try {
			$post_types = isset( $site_post_types[ $blog_id ] )
				? $site_post_types[ $blog_id ]
				: array( 'post', 'page' );

			$post_types = apply_filters( 'extrachill_search_site_post_types', $post_types, $blog_id );

			if ( empty( $post_types ) ) {
				continue;
			}

			$query_args = array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => $args['post_status'],
				'posts_per_page' => -1,
				'orderby'        => $args['orderby'],
				'order'          => $args['order'],
			);

			if ( ! empty( $search_term ) ) {
				$query_args['s'] = extrachill_normalize_search_term( $search_term );
			}

			if ( ! empty( $args['meta_query'] ) ) {
				$query_args['meta_query'] = $args['meta_query'];
			}

			if ( ! empty( $args['tax_query'] ) ) {
				$query_args['tax_query'] = $args['tax_query'];
			}

			$query = new WP_Query( $query_args );

			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					global $post;

					$post_title = get_the_title();
					$permalink  = get_permalink();

					if ( $post->post_type === 'reply' && ! empty( $post->post_parent ) ) {
						$topic_id = $post->post_parent;
						$topic_title = get_the_title( $topic_id );

						if ( ! empty( $topic_title ) ) {
							$post_title = 'Re: ' . $topic_title;
							$permalink = get_permalink( $topic_id ) . '#post-' . $post->ID;
						}
					}

					$taxonomies = array();
					$public_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
					foreach ( $public_taxonomies as $taxonomy ) {
						$terms = get_the_terms( $post->ID, $taxonomy->name );
						if ( $terms && ! is_wp_error( $terms ) ) {
							$taxonomies[ $taxonomy->name ] = wp_list_pluck( $terms, 'term_id', 'name' );
						}
					}

					$thumbnail_id = get_post_thumbnail_id( $post->ID );
					$thumbnail_data = array();
					if ( $thumbnail_id ) {
						$thumbnail_metadata = wp_get_attachment_metadata( $thumbnail_id );
						$thumbnail_data = array(
							'thumbnail_id'     => $thumbnail_id,
							'thumbnail_url'    => wp_get_attachment_image_url( $thumbnail_id, 'medium_large' ),
							'thumbnail_srcset' => wp_get_attachment_image_srcset( $thumbnail_id, 'medium_large' ),
							'thumbnail_sizes'  => wp_get_attachment_image_sizes( $thumbnail_id, 'medium_large' ),
							'thumbnail_alt'    => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
							'thumbnail_width'  => isset( $thumbnail_metadata['sizes']['medium_large']['width'] ) ? $thumbnail_metadata['sizes']['medium_large']['width'] : null,
							'thumbnail_height' => isset( $thumbnail_metadata['sizes']['medium_large']['height'] ) ? $thumbnail_metadata['sizes']['medium_large']['height'] : null,
						);
					}

					$result = array(
						'ID'            => $post->ID,
						'post_title'    => $post_title,
						'post_content'  => get_the_content(),
						'post_excerpt'  => has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30 ),
						'post_date'     => $post->post_date,
						'post_modified' => $post->post_modified,
						'post_type'     => $post->post_type,
						'post_name'     => $post->post_name,
						'post_author'   => $post->post_author,
						'site_id'       => $blog_id,
						'site_name'     => $blog_details->blogname,
						'site_url'      => parse_url( $blog_details->siteurl, PHP_URL_HOST ),
						'permalink'     => $permalink,
						'taxonomies'    => $taxonomies,
						'thumbnail'     => $thumbnail_data,
					);

					$all_results[] = $result;
				}
				wp_reset_postdata();
			}
		} catch ( Exception $e ) {
			error_log( sprintf( 'Multisite search error on blog %d: %s', $blog_id, $e->getMessage() ) );
		}

		restore_current_blog();
	}

	if ( empty( $all_results ) && ! empty( $search_term ) ) {
		$all_results = extrachill_word_level_search_fallback( $search_term, $blog_ids, $args );
	}

	if ( ! empty( $search_term ) ) {
		foreach ( $all_results as $index => $result ) {
			$all_results[ $index ]['_search_score'] = extrachill_calculate_search_score( $result, $search_term );
		}

		usort(
			$all_results,
			function ( $a, $b ) {
				$score_diff = $b['_search_score'] - $a['_search_score'];
				if ( $score_diff !== 0 ) {
					return $score_diff;
				}
				return strtotime( $b['post_date'] ) - strtotime( $a['post_date'] );
			}
		);
	} elseif ( $args['orderby'] === 'date' ) {
		usort(
			$all_results,
			function ( $a, $b ) use ( $args ) {
				$comparison = strtotime( $b['post_date'] ) - strtotime( $a['post_date'] );
				return $args['order'] === 'ASC' ? -$comparison : $comparison;
			}
		);
	}

	$total_results = count( $all_results );
	$all_results = array_slice( $all_results, $args['offset'], $args['limit'] );

	/**
	 * Fires after a search is performed.
	 *
	 * @param string $search_term   The search query.
	 * @param int    $total_results Total number of results found.
	 * @param string $referer       The page user was on before searching.
	 */
	do_action( 'extrachill_search_performed', $search_term, $total_results, wp_get_referer() );

	// Track analytics.
	if ( ! empty( trim( $search_term ) ) ) {
		$analytics_data = array(
			'event_type' => 'search',
			'event_data' => array(
				'search_term'  => $search_term,
				'result_count' => (int) $total_results,
			),
			'source_url' => wp_get_referer() ?: '',
		);
		
		$ability = wp_get_ability( 'extrachill/track-analytics-event' );
		if ( $ability ) {
			$ability->execute( $analytics_data );
		}
	}

	if ( $args['return_count'] ) {
		return array(
			'results' => $all_results,
			'total'   => $total_results,
		);
	}

	return $all_results;
}

/**
 * Calculate weighted relevance score prioritizing exact matches.
 *
 * Scoring weights: exact title (1000), title phrase (500), all words (400), content matches (max 200), recency (max 100).
 *
 * @param array  $result      Search result data.
 * @param string $search_term Search query.
 * @return int Relevance score.
 */
function extrachill_calculate_search_score( $result, $search_term ) {
	$score = 0;
	$term_lower = strtolower( extrachill_normalize_search_term( $search_term ) );
	$title_lower = strtolower( extrachill_normalize_search_term( $result['post_title'] ) );
	$content_lower = strtolower( extrachill_normalize_search_term( strip_tags( $result['post_content'] ) ) );

	$weights = apply_filters(
		'extrachill_search_scoring_weights',
		array(
			'exact_title_match'   => 1000,
			'title_phrase_match'  => 500,
			'title_start_bonus'   => 200,
			'all_words_in_title'  => 400,
			'per_word_in_title'   => 25,
			'content_per_match'   => 50,
			'content_max'         => 200,
			'recency_max'         => 100,
			'recency_days'        => 365,
		)
	);

	if ( $title_lower === $term_lower ) {
		$score += $weights['exact_title_match'];
	} elseif ( strpos( $title_lower, $term_lower ) !== false ) {
		$score += $weights['title_phrase_match'];
		if ( strpos( $title_lower, $term_lower ) === 0 ) {
			$score += $weights['title_start_bonus'];
		}
	} else {
		$search_words = preg_split( '/\s+/', $term_lower, -1, PREG_SPLIT_NO_EMPTY );
		$words_found = 0;

		foreach ( $search_words as $word ) {
			if ( strpos( $title_lower, $word ) !== false ) {
				$words_found++;
			}
		}

		if ( $words_found === count( $search_words ) && count( $search_words ) > 1 ) {
			$score += $weights['all_words_in_title'];
			$score += $words_found * $weights['per_word_in_title'];
		}
	}

	$content_count = substr_count( $content_lower, $term_lower );
	$score += min( $content_count * $weights['content_per_match'], $weights['content_max'] );

	$days_old = ( time() - strtotime( $result['post_date'] ) ) / DAY_IN_SECONDS;
	$recency_score = max( 0, $weights['recency_max'] - ( $days_old / ( $weights['recency_days'] / 100 ) ) );
	$score += $recency_score;

	return $score;
}
