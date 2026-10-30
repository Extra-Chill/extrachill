<?php
/**
 * Authoritative frontend-path resolution across the Extra Chill network.
 *
 * @package ExtraChillNetwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Maximum normalized paths accepted by one target-local probe. */
const EC_FRONTEND_PATH_RESOLVER_MAX_PATHS      = 100;
const EC_FRONTEND_PATH_RESOLVER_MAX_PATH_BYTES = 2048;
const EC_FRONTEND_PATH_RESOLVER_MAX_BODY_BYTES = 65536;

/**
 * Normalize a host-relative frontend path for canonical comparisons.
 *
 * Query strings and fragments do not identify content, so they are removed.
 * Absolute and protocol-relative URLs are rejected rather than used to infer
 * network ownership.
 *
 * @param string $path Host-relative frontend path.
 * @return string|null Normalized path, or null when it is not host-relative.
 */
function ec_normalize_frontend_path( string $path ): ?string {
	$path = trim( $path );
	if ( '' === $path || strlen( $path ) > EC_FRONTEND_PATH_RESOLVER_MAX_PATH_BYTES || str_starts_with( $path, '//' ) || ! str_starts_with( $path, '/' ) ) {
		return null;
	}

	$parts = wp_parse_url( $path );
	if ( false === $parts || ! empty( $parts['host'] ) || ! empty( $parts['scheme'] ) || ! isset( $parts['path'] ) ) {
		return null;
	}

	$path = '/' . ltrim( (string) $parts['path'], '/' );

	return '/' === $path ? '/' : trailingslashit( $path );
}

/**
 * Resolve frontend paths against every authoritative network site in one scan.
 *
 * Each target receives the whole deduplicated path set in one HTTP loopback and
 * resolves it after its own plugins and rewrite registrations have booted. A
 * result is never unique, unresolved, or ambiguous until every target response
 * has passed the response contract. Failures make valid inputs incomplete.
 *
 * @param string[] $paths Host-relative frontend paths.
 * @param array    $args  Optional `timeout` in seconds (1-15, default 15).
 * @return array{scan:array,results:array}
 */
function ec_resolve_frontend_paths( array $paths, array $args = array() ): array {
	if ( count( $paths ) > EC_FRONTEND_PATH_RESOLVER_MAX_PATHS ) {
		return ec_frontend_path_resolver_rejected_input_results( $paths, 'too_many_inputs', 'The raw input batch exceeds the resolver limit.' );
	}

	$timeout   = isset( $args['timeout'] ) ? (int) $args['timeout'] : 15;
	$timeout   = min( 15, max( 1, $timeout ) );
	$results   = array();
	$unique    = array();
	$raw_bytes = 0;

	foreach ( $paths as $input ) {
		$input      = is_string( $input ) ? $input : '';
		$raw_bytes += strlen( $input );
		if ( strlen( $input ) > EC_FRONTEND_PATH_RESOLVER_MAX_PATH_BYTES || $raw_bytes > EC_FRONTEND_PATH_RESOLVER_MAX_BODY_BYTES ) {
			return ec_frontend_path_resolver_rejected_input_results( $paths, 'input_too_large', 'The raw input batch exceeds the resolver byte limit.' );
		}
		$normalized = ec_normalize_frontend_path( $input );
		$results[]  = array(
			'input'      => $input,
			'path'       => $normalized,
			'candidates' => array(),
			'status'     => null === $normalized ? 'unresolved' : null,
		);
		if ( null !== $normalized ) {
			$unique[ $normalized ] = true;
		}
	}

	$normalized_paths = array_keys( $unique );
	if ( empty( $normalized_paths ) ) {
		return array(
			'scan'    => array(
				'status'   => 'complete',
				'targets'  => array(),
				'failures' => array(),
			),
			'results' => $results,
		);
	}
	$body_bytes = strlen( wp_json_encode( array( 'paths' => $normalized_paths ) ) );
	if ( $body_bytes > EC_FRONTEND_PATH_RESOLVER_MAX_BODY_BYTES ) {
		return ec_frontend_path_resolver_incomplete_results(
			$results,
			array(
				array(
					'code'    => 'body_too_large',
					'message' => 'The normalized request body exceeds the resolver limit.',
				),
			)
		);
	}

	$matches  = array_fill_keys( $normalized_paths, array() );
	$failures = array();
	$targets  = array();
	foreach ( ec_get_blog_ids() as $site_key => $blog_id ) {
		$response = ec_cross_site_rest_request_http(
			$site_key,
			'POST',
			'/extrachill-network/v1/frontend-path-resolution',
			array(
				'body'    => array( 'paths' => $normalized_paths ),
				'timeout' => $timeout,
			)
		);

		$target = array(
			'site_key' => $site_key,
			'blog_id'  => (int) $blog_id,
		);
		if ( is_wp_error( $response ) ) {
			$failures[] = array_merge(
				$target,
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				)
			);
			continue;
		}

		if ( ! is_array( $response ) || 'complete' !== ( $response['status'] ?? null ) || ! isset( $response['results'] ) || ! is_array( $response['results'] ) ) {
			$failures[] = array_merge(
				$target,
				array(
					'code'    => 'malformed_response',
					'message' => 'Target returned an invalid resolver response.',
				)
			);
			continue;
		}

		$malformed = false;
		foreach ( $normalized_paths as $path ) {
			$local = $response['results'][ $path ] ?? null;
			if ( ! is_array( $local ) || ! in_array( $local['status'] ?? null, array( 'resolved', 'unresolved' ), true ) ) {
				$malformed = true;
				break;
			}
			if ( 'resolved' === $local['status'] ) {
				$candidate = $local['candidate'] ?? null;
				if ( ! ec_frontend_path_resolver_valid_candidate( $candidate, $path, $site_key, (int) $blog_id ) ) {
					$malformed = true;
					break;
				}
				$matches[ $path ][ (int) $candidate['blog_id'] . ':' . (int) $candidate['post_id'] ] = $candidate;
			}
		}

		if ( $malformed ) {
			$failures[] = array_merge(
				$target,
				array(
					'code'    => 'malformed_response',
					'message' => 'Target response did not cover every requested path.',
				)
			);
			continue;
		}
		$targets[] = $target;
	}

	if ( ! empty( $failures ) ) {
		return ec_frontend_path_resolver_incomplete_results( $results, $failures, $targets );
	}

	foreach ( $results as &$result ) {
		if ( null === $result['path'] ) {
			continue;
		}
		$candidates = array_values( $matches[ $result['path'] ] );
		usort( $candidates, static fn( array $left, array $right ): int => array( (int) $left['blog_id'], (int) $left['post_id'] ) <=> array( (int) $right['blog_id'], (int) $right['post_id'] ) );
		$result['candidates'] = $candidates;
		$result['status']     = empty( $candidates ) ? 'unresolved' : ( 1 === count( $candidates ) ? 'resolved' : 'ambiguous' );
		if ( 'resolved' === $result['status'] ) {
			$result['candidate'] = $candidates[0];
		}
	}
	unset( $result );

	return array(
		'scan'    => array(
			'status'   => 'complete',
			'targets'  => $targets,
			'failures' => array(),
		),
		'results' => $results,
	);
}

/**
 * Delegate single-path resolution to the batch contract.
 *
 * @param string $path Host-relative frontend path.
 * @param array  $args Optional resolver arguments.
 * @return array
 */
function ec_resolve_frontend_path( string $path, array $args = array() ): array {
	$batch = ec_resolve_frontend_paths( array( $path ), $args );
	if ( empty( $batch['results'] ) ) {
		return array(
			'input'      => $path,
			'path'       => null,
			'candidates' => array(),
			'status'     => 'incomplete',
			'scan'       => $batch['scan'],
		);
	}

	$result         = $batch['results'][0];
	$result['scan'] = $batch['scan'];

	return $result;
}

/**
 * Build incomplete results without treating partial candidate data as correct.
 *
 * @param array $results  Input result rows.
 * @param array $failures Target or batch failures.
 * @param array $targets  Successfully checked targets.
 * @return array
 */
function ec_frontend_path_resolver_incomplete_results( array $results, array $failures, array $targets = array() ): array {
	foreach ( $results as &$result ) {
		if ( null !== $result['path'] ) {
			$result['status'] = 'incomplete';
		}
		$result['failures'] = $failures;
	}
	unset( $result );

	return array(
		'scan'    => array(
			'status'   => 'incomplete',
			'targets'  => $targets,
			'failures' => $failures,
		),
		'results' => $results,
	);
}

/**
 * Reject an oversized raw request before target fanout.
 *
 * @param array  $paths   Raw input paths.
 * @param string $code    Rejection code.
 * @param string $message Rejection message.
 * @return array
 */
function ec_frontend_path_resolver_rejected_input_results( array $paths, string $code, string $message ): array {
	return ec_frontend_path_resolver_incomplete_results(
		array(),
		array(
			array(
				'code'        => $code,
				'message'     => $message,
				'input_count' => count( $paths ),
			),
		)
	);
}

/**
 * Verify target evidence before it contributes to a network result.
 *
 * @param mixed  $candidate Target candidate.
 * @param string $path      Requested normalized path.
 * @param string $site_key  Expected target site key.
 * @param int    $blog_id   Expected target blog ID.
 * @return bool
 */
function ec_frontend_path_resolver_valid_candidate( $candidate, string $path, string $site_key, int $blog_id ): bool {
	if ( ! is_array( $candidate ) || ( $candidate['blog_id'] ?? null ) !== $blog_id || ! isset( $candidate['post_id'] ) || ! is_int( $candidate['post_id'] ) || 0 >= $candidate['post_id'] || ! isset( $candidate['post_type'] ) || ! is_string( $candidate['post_type'] ) || '' === trim( $candidate['post_type'] ) || ! isset( $candidate['canonical_url'] ) || ! is_string( $candidate['canonical_url'] ) || ! filter_var( $candidate['canonical_url'], FILTER_VALIDATE_URL ) ) {
		return false;
	}

	$url_parts      = wp_parse_url( $candidate['canonical_url'] );
	$site_parts     = wp_parse_url( (string) ec_get_site_url( $site_key ) );
	$canonical_path = $candidate['canonical_path'] ?? null;
	if ( false === $url_parts || false === $site_parts || ! is_string( $canonical_path ) || ! isset( $url_parts['scheme'], $url_parts['host'], $url_parts['path'], $site_parts['host'] ) || ! in_array( strtolower( $url_parts['scheme'] ), array( 'http', 'https' ), true ) || strtolower( $site_parts['host'] ) !== strtolower( $url_parts['host'] ) ) {
		return false;
	}

	return ec_normalize_frontend_path( $canonical_path ) === $path && ec_normalize_frontend_path( $url_parts['path'] ) === $path;
}

/** Register the target-local batch probe. */
function ec_register_frontend_path_resolver_route(): void {
	register_rest_route(
		'extrachill-network/v1',
		'/frontend-path-resolution',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ec_frontend_path_resolver_rest_callback',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'ec_register_frontend_path_resolver_route' );

/**
 * Resolve all normalized paths inside the currently booted target site.
 *
 * @param WP_REST_Request $request Request containing `paths`.
 * @return WP_REST_Response
 */
function ec_frontend_path_resolver_rest_callback( WP_REST_Request $request ): WP_REST_Response {
	$paths = $request->get_json_params()['paths'] ?? null;
	if ( ! is_array( $paths ) || count( $paths ) > EC_FRONTEND_PATH_RESOLVER_MAX_PATHS || ! ec_frontend_path_resolver_paths_within_byte_limit( $paths ) ) {
		return new WP_REST_Response( array( 'status' => 'invalid_request' ), 400 );
	}

	$results = array();
	foreach ( $paths as $path ) {
		$path = is_string( $path ) ? ec_normalize_frontend_path( $path ) : null;
		if ( null === $path ) {
			return new WP_REST_Response( array( 'status' => 'invalid_request' ), 400 );
		}
		$results[ $path ] = ec_frontend_path_resolve_local( $path );
	}

	return new WP_REST_Response(
		array(
			'status'  => 'complete',
			'results' => $results,
		)
	);
}

/**
 * Check target-local raw path byte limits before route resolution.
 *
 * @param array $paths Raw request paths.
 * @return bool
 */
function ec_frontend_path_resolver_paths_within_byte_limit( array $paths ): bool {
	$bytes = 0;
	foreach ( $paths as $path ) {
		if ( ! is_string( $path ) || strlen( $path ) > EC_FRONTEND_PATH_RESOLVER_MAX_PATH_BYTES ) {
			return false;
		}
		$bytes += strlen( $path );
		if ( $bytes > EC_FRONTEND_PATH_RESOLVER_MAX_BODY_BYTES ) {
			return false;
		}
	}

	return true;
}

/**
 * Resolve one exact canonical path in the currently booted site.
 *
 * @param string $path Normalized host-relative path.
 * @return array
 */
function ec_frontend_path_resolve_local( string $path ): array {
	$post_id = url_to_postid( home_url( $path ) );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return array( 'status' => 'unresolved' );
	}

	$canonical_url  = get_permalink( $post );
	$canonical_path = $canonical_url ? ec_normalize_frontend_path( (string) wp_parse_url( $canonical_url, PHP_URL_PATH ) ) : null;
	if ( $path !== $canonical_path ) {
		return array( 'status' => 'unresolved' );
	}

	return array(
		'status'    => 'resolved',
		'candidate' => array(
			'blog_id'        => get_current_blog_id(),
			'post_id'        => (int) $post->ID,
			'post_type'      => $post->post_type,
			'canonical_url'  => $canonical_url,
			'canonical_path' => $canonical_path,
		),
	);
}
