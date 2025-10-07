<?php
/**
 * Core Search Functions for ExtraChill Search Plugin
 *
 * @package ExtraChill\Search
 * @since 1.0.0
 */

/**
 * Get all network sites with static caching
 *
 * @since 1.0.0
 * @return array Array of site data or empty array if not multisite
 */
function extrachill_get_network_sites() {
	static $sites_cache = null;

	if ( $sites_cache !== null ) {
		return $sites_cache;
	}

	if ( ! is_multisite() ) {
		return array();
	}

	$network_sites = get_sites( array(
		'network_id' => get_current_network_id(),
		'public'     => 1,
		'archived'   => 0,
		'spam'       => 0,
		'deleted'    => 0,
	) );

	$sites = array();
	foreach ( $network_sites as $site ) {
		$blog_details = get_blog_details( $site->blog_id );
		if ( $blog_details ) {
			$sites[] = array(
				'id'   => (int) $site->blog_id,
				'name' => $blog_details->blogname,
				'url'  => parse_url( $blog_details->siteurl, PHP_URL_HOST ),
			);
		}
	}

	$sites_cache = $sites;
	return $sites;
}

/**
 * Resolve site URLs to blog IDs
 *
 * Uses get_blog_id_from_url() with WordPress native blog-id-cache for performance.
 *
 * @since 1.0.0
 * @param array $site_urls Array of site URLs to resolve
 * @return array Resolved blog IDs
 */
function extrachill_resolve_site_urls( $site_urls ) {
	if ( ! is_array( $site_urls ) || empty( $site_urls ) ) {
		return array();
	}

	$blog_ids = array();

	foreach ( $site_urls as $url ) {
		$blog_id = get_blog_id_from_url( $url, '/' );
		if ( $blog_id ) {
			$blog_ids[] = $blog_id;
		}
	}

	return $blog_ids;
}

/**
 * Search across multisite network
 *
 * Uses domain-based site resolution with get_blog_id_from_url() for maintainable code.
 * WordPress blog-id-cache provides automatic performance optimization.
 *
 * @since 1.0.0
 * @param string $search_term Search query (empty for all posts)
 * @param array  $site_urls   Site URLs to search (empty = all network sites)
 * @param array  $args        Query arguments
 * @return array Search results or array with 'results' and 'total' if return_count is true
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

	// Apply filter for customization
	$args = apply_filters( 'extrachill_search_args', $args, $search_term, $site_urls );

	// Resolve sites to search
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

	foreach ( $blog_ids as $blog_id ) {
		switch_to_blog( $blog_id );

		try {
			$blog_details = get_blog_details( $blog_id );

			if ( ! $blog_details ) {
				continue;
			}

			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$post_types = array_diff( $post_types, array( 'attachment' ) );

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
				$query_args['s'] = $search_term;
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

					// Fetch taxonomies for the post
					$taxonomies = array();
					$public_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
					foreach ( $public_taxonomies as $taxonomy ) {
						$terms = get_the_terms( $post->ID, $taxonomy->name );
						if ( $terms && ! is_wp_error( $terms ) ) {
							$taxonomies[$taxonomy->name] = wp_list_pluck( $terms, 'term_id', 'name' );
						}
					}

					$result = array(
						'ID'           => $post->ID,
						'post_title'   => get_the_title(),
						'post_content' => get_the_content(),
						'post_excerpt' => has_excerpt() ? get_the_excerpt() : wp_trim_words( get_the_content(), 30 ),
						'post_date'    => $post->post_date,
						'post_type'    => $post->post_type,
						'post_name'    => $post->post_name,
						'post_author'  => $post->post_author,
						'site_id'      => $blog_id,
						'site_name'    => $blog_details->blogname,
						'site_url'     => parse_url( $blog_details->siteurl, PHP_URL_HOST ),
						'permalink'    => get_permalink(),
						'taxonomies'   => $taxonomies,
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

	if ( $args['orderby'] === 'date' ) {
		usort( $all_results, function ( $a, $b ) use ( $args ) {
			$comparison = strtotime( $b['post_date'] ) - strtotime( $a['post_date'] );
			return $args['order'] === 'ASC' ? -$comparison : $comparison;
		});
	}

	$total_results = count( $all_results );
	$all_results = array_slice( $all_results, $args['offset'], $args['limit'] );

	if ( $args['return_count'] ) {
		return array(
			'results' => $all_results,
			'total'   => $total_results,
		);
	}

	return $all_results;
}

/**
 * Generate contextual excerpt centered around search term
 *
 * @since 1.0.0
 * @param string $content     Content to excerpt
 * @param string $search_term Term to center around
 * @param int    $word_limit  Maximum words. Default 30
 * @return string Contextual excerpt
 */
function ec_get_contextual_excerpt_multisite( $content, $search_term, $word_limit = 30 ) {
	$content = wp_strip_all_tags( $content );
	$words   = explode( ' ', $content );

	if ( count( $words ) <= $word_limit ) {
		return $content;
	}

	$search_pos = stripos( $content, $search_term );
	if ( $search_pos !== false ) {
		$start_word    = max( 0, floor( $search_pos / 6 ) - ( $word_limit / 2 ) );
		$excerpt_words = array_slice( $words, $start_word, $word_limit );
		return ( $start_word > 0 ? '...' : '' ) . implode( ' ', $excerpt_words ) . '...';
	}

	return implode( ' ', array_slice( $words, 0, $word_limit ) ) . '...';
}

/**
 * Register fallback contextual excerpt function for themes without native implementation
 *
 * @since 1.0.0
 */
if ( ! function_exists( 'ec_get_contextual_excerpt' ) ) {
	if ( ! function_exists( 'ec_register_contextual_excerpt_fallback' ) ) {
		function ec_register_contextual_excerpt_fallback() {
			if ( function_exists( 'ec_get_contextual_excerpt' ) ) {
				return;
			}

			$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;

			if ( is_object( $theme ) && method_exists( $theme, 'get_template' ) ) {
				$template   = $theme->get_template();
				$stylesheet = method_exists( $theme, 'get_stylesheet' ) ? $theme->get_stylesheet() : '';

				if ( 'extrachill-community' === $template || 'extrachill-community' === $stylesheet ) {
					return;
				}
			}

			function ec_get_contextual_excerpt( $content, $search_term, $word_limit = 30 ) {
				$position = stripos( $content, $search_term );

				if ( $position === false ) {
					$excerpt = '...' . wp_trim_words( $content, $word_limit ) . '...';
				} else {
					$words = explode( ' ', $content );
					$match_position = 0;

					foreach ( $words as $index => $word ) {
						if ( stripos( $word, $search_term ) !== false ) {
							$match_position = $index;
							break;
						}
					}

					$start  = max( 0, $match_position - floor( $word_limit / 2 ) );
					$length = min( count( $words ) - $start, $word_limit );

					$excerpt_words = array_slice( $words, $start, $length );

					$prefix = $start > 0 ? '...' : '';
					$suffix = ( $start + $length ) < count( $words ) ? '...' : '';

					$excerpt = $prefix . implode( ' ', $excerpt_words ) . $suffix;
				}

				return $excerpt;
			}
		}
	}

	add_action( 'after_setup_theme', 'ec_register_contextual_excerpt_fallback', 9 );
}