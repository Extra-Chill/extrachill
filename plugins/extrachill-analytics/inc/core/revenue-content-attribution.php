<?php
/**
 * Persisted content ownership for revenue rows.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return a normalized host-relative path suitable for the Network resolver.
 *
 * @param string $value CSV path or URL.
 * @return string|null Host-relative path, or null when it cannot be resolved.
 */
function extrachill_analytics_revenue_frontend_path( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value || 0 === strpos( $value, '//' ) ) {
		return null;
	}

	if ( '/' !== $value[0] ) {
		return null;
	}
	$parts = wp_parse_url( $value );
	if ( false === $parts || ! empty( $parts['host'] ) || ! empty( $parts['scheme'] ) ) {
		return null;
	}
	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
	if ( '' === $path || '/' !== $path[0] ) {
		return null;
	}

	return '/' === $path ? '/' : '/' . trim( $path, '/' ) . '/';
}

/**
 * Resolve unresolved content paths through the Network batch contract.
 *
 * This deliberately runs before the ingestion transaction. An incomplete scan
 * must never partially replace a deterministic snapshot.
 *
 * @param array<int,string> $paths Unique host-relative paths.
 * @return array{success:bool,results:array,error:string}
 */
function extrachill_analytics_revenue_resolve_network_paths( array $paths ) {
	if ( empty( $paths ) ) {
		return array(
			'success' => true,
			'results' => array(),
			'error'   => '',
		);
	}
	if ( ! function_exists( 'ec_resolve_frontend_paths' ) ) {
		return array(
			'success' => false,
			'results' => array(),
			'error'   => 'The Extra Chill Network batch path resolver is unavailable or outdated; snapshot replacement was aborted.',
		);
	}

	$results = array();
	$chunk   = array();
	$bytes   = 0;
	foreach ( $paths as $requested_path ) {
		$path_bytes = strlen( $requested_path );
		if ( count( $chunk ) >= 100 || ( ! empty( $chunk ) && $bytes + $path_bytes > 64000 ) ) {
			$validated = extrachill_analytics_revenue_validate_network_scan( ec_resolve_frontend_paths( $chunk ), $chunk );
			if ( ! $validated['success'] ) {
				return array(
					'success' => false,
					'results' => array(),
					'error'   => $validated['error'],
				);
			}
			foreach ( $validated['results'] as $result_path => $result ) {
				$results[ $result_path ] = $result;
			}
			$chunk = array();
			$bytes = 0;
		}
		$chunk[] = $requested_path;
		$bytes  += $path_bytes;
	}
	if ( ! empty( $chunk ) ) {
		$validated = extrachill_analytics_revenue_validate_network_scan( ec_resolve_frontend_paths( $chunk ), $chunk );
		if ( ! $validated['success'] ) {
			return array(
				'success' => false,
				'results' => array(),
				'error'   => $validated['error'],
			);
		}
		foreach ( $validated['results'] as $result_path => $result ) {
			$results[ $result_path ] = $result;
		}
	}

	return array(
		'success' => true,
		'results' => $results,
		'error'   => '',
	);
}

/**
 * Validate a nominally complete Network response before replacement can begin.
 *
 * @param mixed             $scan Network response.
 * @param array<int,string> $paths Requested unique paths.
 * @return array{success:bool,results:array,error:string}
 */
function extrachill_analytics_revenue_validate_network_scan( $scan, array $paths ) {
	if ( ! is_array( $scan ) || 'complete' !== ( $scan['scan']['status'] ?? '' ) || ! isset( $scan['results'] ) || ! is_array( $scan['results'] ) ) {
		return array(
			'success' => false,
			'results' => array(),
			'error'   => 'The Network path resolver returned an incomplete or malformed scan; snapshot replacement was aborted.',
		);
	}
	$wanted = array_fill_keys( $paths, true );
	$found  = array();
	foreach ( $scan['results'] as $result ) {
		if ( ! is_array( $result ) || ! isset( $result['path'] ) || ! is_string( $result['path'] ) || ! isset( $wanted[ $result['path'] ] ) || isset( $found[ $result['path'] ] ) || ! isset( $result['status'] ) || ! in_array( $result['status'], array( 'resolved', 'unresolved', 'ambiguous' ), true ) ) {
			return array(
				'success' => false,
				'results' => array(),
				'error'   => 'The Network path resolver returned an invalid complete-scan result; snapshot replacement was aborted.',
			);
		}
		if ( 'resolved' === $result['status'] && ( ! isset( $result['candidate'] ) || ! is_array( $result['candidate'] ) || ! isset( $result['candidate']['blog_id'], $result['candidate']['post_id'], $result['candidate']['canonical_url'] ) || ! is_int( $result['candidate']['blog_id'] ) || $result['candidate']['blog_id'] <= 0 || ! is_int( $result['candidate']['post_id'] ) || $result['candidate']['post_id'] <= 0 || ! is_string( $result['candidate']['canonical_url'] ) || ! preg_match( '#^https?://#i', $result['candidate']['canonical_url'] ) || false === filter_var( $result['candidate']['canonical_url'], FILTER_VALIDATE_URL ) ) ) {
			return array(
				'success' => false,
				'results' => array(),
				'error'   => 'The Network path resolver returned incomplete resolved evidence; snapshot replacement was aborted.',
			);
		}
		$found[ $result['path'] ] = $result;
	}
	if ( count( $found ) !== count( $wanted ) ) {
		return array(
			'success' => false,
			'results' => array(),
			'error'   => 'The Network path resolver did not return exactly one result per path; snapshot replacement was aborted.',
		);
	}
	return array(
		'success' => true,
		'results' => $found,
		'error'   => '',
	);
}

/**
 * Read persisted content metadata in its owning site context.
 *
 * @param int      $blog_id Owning content blog.
 * @param callable $callback Metadata reader.
 * @return mixed Callback result.
 */
function extrachill_analytics_revenue_with_content_blog( $blog_id, $callback ) {
	$switched = false;
	if ( $blog_id > 0 && function_exists( 'switch_to_blog' ) && get_current_blog_id() !== (int) $blog_id ) {
		$switched = (bool) switch_to_blog( $blog_id );
	}
	try {
		return call_user_func( $callback );
	} finally {
		if ( $switched && function_exists( 'restore_current_blog' ) ) {
			restore_current_blog();
		}
	}
}

/**
 * Read persisted content metadata under its authoritative owning blog.
 *
 * @param int $blog_id Owning content blog.
 * @param int $post_id Owning content post.
 * @return array|null Published metadata, or null.
 */
function extrachill_analytics_revenue_content_metadata( $blog_id, $post_id ) {
	return extrachill_analytics_revenue_with_content_blog(
		$blog_id,
		static function () use ( $post_id ) {
			if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) ) {
				return null;
			}
			$post      = get_post( $post_id );
			$permalink = $post ? get_permalink( $post_id ) : '';
			$terms     = get_the_terms( $post_id, 'category' );
			return array(
				'title'          => $post ? (string) $post->post_title : '',
				'url'            => is_string( $permalink ) ? $permalink : '',
				'path'           => is_string( $permalink ) ? wp_make_link_relative( $permalink ) : '',
				'published_date' => $post ? (string) $post->post_date : '',
				'categories'     => ( is_array( $terms ) && ! empty( $terms ) ) ? wp_list_pluck( $terms, 'slug' ) : array( 'uncategorized' ),
				'format'         => extrachill_analytics_classify_format( $post_id ),
			);
		}
	);
}
