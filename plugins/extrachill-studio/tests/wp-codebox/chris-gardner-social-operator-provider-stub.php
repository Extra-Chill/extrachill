<?php
/**
 * Plugin Name: Gardner Social Operator Provider Boundary
 * Description: Fail-closed deterministic HTTP boundary for the Studio operator recipe.
 */

defined( 'ABSPATH' ) || exit;

// Keep WordPress maintenance traffic out of the provider-effect fixture entirely.
remove_action( 'admin_init', '_maybe_update_core' );
remove_action( 'admin_init', '_maybe_update_plugins' );
remove_action( 'admin_init', '_maybe_update_themes' );
remove_action( 'wp_version_check', 'wp_version_check' );
remove_action( 'wp_update_plugins', 'wp_update_plugins' );
remove_action( 'wp_update_themes', 'wp_update_themes' );
remove_action( 'init', 'wp_schedule_update_checks' );
add_filter( 'wp_should_disable_pings_for_environment', '__return_true' );

/** Append one sanitized provider call without request payloads or credentials. */
function ec_studio_operator_record_provider_call( string $method, string $host, string $path, string $provider_call, string $payload_hash = '' ): void {
	$ledger   = get_option( 'ec_studio_operator_provider_ledger', array() );
	$ledger   = is_array( $ledger ) ? $ledger : array();
	$entry    = array(
		'sequence'      => count( $ledger ) + 1,
		'method'        => strtoupper( $method ),
		'host'          => strtolower( $host ),
		'path'          => $path,
		'provider_call' => $provider_call,
	);
	if ( '' !== $payload_hash ) {
		$entry['payload_sha256'] = $payload_hash;
	}
	$ledger[] = $entry;
	update_option( 'ec_studio_operator_provider_ledger', $ledger, false );
}

/** Build a WordPress HTTP response accepted by Data Machine's real client. */
function ec_studio_operator_http_response( int $code, array $body ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Fixture failure' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

add_filter(
	'pre_http_request',
	static function ( $preempt, array $args, string $url ) {
		$parts  = wp_parse_url( $url );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '/' );
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$home   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		// Public fixture media and canonical article fetches stay inside WordPress.
		if ( '' !== $home && $home === $host ) {
			return $preempt;
		}

		if ( 'graph.facebook.com' === $host ) {
			$state = get_option( 'ec_studio_operator_provider_state', array() );
			$state = is_array( $state ) ? $state : array();

			if ( 'GET' === $method && preg_match( '#/comments$#', $path ) ) {
				$mode = (string) ( $state['comments_mode'] ?? 'page' );
				ec_studio_operator_record_provider_call( $method, $host, $path, 'instagram.comments.' . $mode );
				if ( 'empty' === $mode ) {
					return ec_studio_operator_http_response( 200, array( 'data' => array() ) );
				}
				$query = array();
				parse_str( (string) ( $parts['query'] ?? '' ), $query );
				if ( 'partial' === $mode && ! empty( $query['after'] ) ) {
					return new WP_Error( 'http_request_failed', 'Deterministic second-page interruption.' );
				}
				$body = array(
					'data' => array(
						array(
							'id'         => 'ig-comment-1',
							'text'       => 'What time does the first band start?',
							'timestamp'  => '2026-08-23T20:00:00+0000',
							'username'   => 'charlestonmusicfan',
							'like_count' => 2,
						),
					),
				);
				if ( 'partial' === $mode ) {
					$body['paging'] = array(
						'cursors' => array( 'after' => 'fixture-next-page' ),
						'next'    => 'https://graph.facebook.com/fixture/comments?after=fixture-next-page',
					);
				}
				return ec_studio_operator_http_response( 200, $body );
			}

			if ( 'POST' === $method && preg_match( '#/replies$#', $path ) ) {
				ec_studio_operator_record_provider_call( $method, $host, $path, 'instagram.comment-reply' );
				return ec_studio_operator_http_response( 200, array( 'id' => 'ig-reply-1' ) );
			}

			if ( 'POST' === $method && preg_match( '#/media_publish$#', $path ) ) {
				ec_studio_operator_record_provider_call( $method, $host, $path, 'instagram.publish-effect' );
				return ec_studio_operator_http_response( 200, array( 'id' => 'ig-media-operator-1' ) );
			}

			if ( 'POST' === $method && preg_match( '#/media$#', $path ) ) {
				$body         = is_array( $args['body'] ?? null ) ? $args['body'] : array();
				$caption_hash = isset( $body['caption'] ) ? hash( 'sha256', (string) $body['caption'] ) : '';
				ec_studio_operator_record_provider_call( $method, $host, $path, 'instagram.create-container', $caption_hash );
				return ec_studio_operator_http_response( 200, array( 'id' => 'ig-container-operator-1' ) );
			}

			if ( 'GET' === $method && str_contains( $path, 'ig-container-operator-1' ) ) {
				ec_studio_operator_record_provider_call( $method, $host, $path, 'instagram.container-status' );
				return ec_studio_operator_http_response( 200, array( 'status_code' => 'FINISHED' ) );
			}

			if ( 'GET' === $method && str_contains( $path, 'ig-media-operator-1' ) ) {
				ec_studio_operator_record_provider_call( $method, $host, $path, 'instagram.permalink' );
				return ec_studio_operator_http_response(
					200,
					array( 'id' => 'ig-media-operator-1', 'permalink' => 'https://www.instagram.com/p/ig-media-operator-1/' )
				);
			}
		}

		if ( 'bsky.social' === $host ) {
			$state = get_option( 'ec_studio_operator_provider_state', array() );
			$state = is_array( $state ) ? $state : array();
			if ( 'POST' === $method && str_ends_with( $path, '/com.atproto.server.createSession' ) ) {
				ec_studio_operator_record_provider_call( $method, $host, $path, 'bluesky.create-session' );
				return ec_studio_operator_http_response(
					200,
					array(
						'accessJwt' => 'fixture-access-jwt',
						'did'       => 'did:plc:extrachillfixture',
						'handle'    => 'extrachill.com',
						'pdsUrl'    => 'https://bsky.social',
					)
				);
			}
			if ( 'POST' === $method && str_ends_with( $path, '/com.atproto.repo.uploadBlob' ) ) {
				ec_studio_operator_record_provider_call( $method, $host, $path, 'bluesky.upload-blob' );
				$remaining = max( 0, (int) ( $state['bluesky_failures_remaining'] ?? 0 ) );
				if ( $remaining > 0 ) {
					$state['bluesky_failures_remaining'] = $remaining - 1;
					update_option( 'ec_studio_operator_provider_state', $state, false );
					return new WP_Error( 'http_request_failed', 'Deterministic transient Bluesky upload interruption.' );
				}
				return ec_studio_operator_http_response(
					200,
					array( 'blob' => array( '$type' => 'blob', 'ref' => array( '$link' => 'fixture-blob-cid' ), 'mimeType' => 'image/jpeg', 'size' => 128 ) )
				);
			}
			if ( 'POST' === $method && str_ends_with( $path, '/com.atproto.repo.createRecord' ) ) {
				$decoded      = json_decode( (string) ( $args['body'] ?? '' ), true );
				$caption_hash = is_array( $decoded ) ? hash( 'sha256', (string) ( $decoded['record']['text'] ?? '' ) ) : '';
				ec_studio_operator_record_provider_call( $method, $host, $path, 'bluesky.publish-effect', $caption_hash );
				return ec_studio_operator_http_response(
					200,
					array( 'uri' => 'at://did:plc:extrachillfixture/app.bsky.feed.post/bsky-operator-1', 'cid' => 'fixture-post-cid' )
				);
			}
		}

		ec_studio_operator_record_provider_call( $method, $host, $path, 'blocked-unexpected' );
		return new WP_Error( 'ec_studio_operator_unexpected_network', 'Unexpected external URL blocked by the operator recipe.' );
	},
	1,
	3
);
